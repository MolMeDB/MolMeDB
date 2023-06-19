import re, os, time
from general.file import File
import paramiko
from cosmo.cuby4 import CUBY4

CODE_INIT = 0
CODE_OK = 1
CODE_WAITING = 2
CODE_ERR = 3

CODE_EXISTS = 10
CODE_IS_RUNNING = 11
CODE_HAS_RESULT = 12

STEP_INPUT_CHECK = 0
STEP_INIT = 1
STEP_CHECK_RES = 2
STEP_REMOTE_FOLDER = 3
STEP_UPLOAD = 4
STEP_CUBY_CHECK_STATUS = 5
STEP_CUBY_GENERATE = 6
STEP_CUBY_UPLOAD = 7
STEP_CUBY_RUN = 8
STEP_CUBY_RUN_JOB = 81
STEP_COSMO_PREPARE = 9
STEP_COSMO_RUN = 10
STEP_COSMO_DOWNLOAD = 11

sshClient = None

ansi_escape = re.compile(r'''
    \x1B  # ESC
    (?:   # 7-bit C1 Fe (except CSI)
        [@-Z\\-_]
    |     # or [ for CSI, followed by a control sequence
        \[
        [0-?]*  # Parameter bytes
        [ -/]*  # Intermediate bytes
        [@-~]   # Final byte
    )
''', re.VERBOSE)

# paramiko.util.log_to_file("paramiko.log")

def _err(text, sshClient = None):
    print("Error:", text)
    if sshClient: sshClient.close()
    exit()

def _step(num, text):
    print("STEP", str(num) + ":", text)

def _log(step, code, suffix = ""):
    print("_LOG_: " + str(step) + "/" + str(code) + " [" + suffix + "]")


def __main__(confPath, host, username, password, charge=0, cpu=8, ram=32, limitHs=10, membrane=None, membraneName = None, temp=25, queue="elixir", cosmo="perm", forceRun = False, reRun = False):
    # Must be folder of conformers
    confPath, file = File.getFolder(confPath)
    # Check if folder exists
    if not os.path.exists(confPath):
        _log(STEP_INPUT_CHECK, CODE_ERR)
        _err("Target folder not exists.")

    if not os.path.exists(membrane):
        _log(STEP_INPUT_CHECK, CODE_ERR)
        _err("Membrane file not exists.")

    if cosmoType not in ["perm", "mic"]:
        _log(STEP_INPUT_CHECK, CODE_ERR)
        _err("Invalid cosmo type.")

    if file is None:
        # Check if folder contains some conformers
        files = os.listdir(confPath)
        files = list(filter(lambda f: f.endswith(".sdf"), files))
        # Add prefixes
        files = [os.path.abspath(confPath + f) for f in files]
    elif not os.path.exists(file):
        _log(STEP_INPUT_CHECK, CODE_ERR)
        _err("SDF file", file, "not exists.")
    else:
        files = [file]

    if len(files) == 0:
        _log(STEP_INPUT_CHECK, CODE_ERR)
        _err("No SDF files found.")

    _log(STEP_INPUT_CHECK, CODE_OK)


    #########################################################
    #################### STEP 0 #############################
    ############ Connect to the remote server ###############
    global sshClient
    if sshClient == None:
        _log(STEP_INIT, CODE_INIT)
        _step(0, "SSH connection inizialize.")
        sshClient = SSHClient(username=username, password=password,host=host, port=22)

    
    # Init SDF File instances
    files = [SDFFile(f, sshClient=sshClient, charge=charge, forceRun=forceRun, reRun=reRun) for f in files]
    _step(0, "Done")

    cosmo = COSMO(
        sshClient=sshClient, 
        forceRun = forceRun, 
        type=cosmoType,
        temperature=temp,
        membrane=membrane,
        membraneName=membraneName,
        files=files,
        queue=queue)
    
    _log(STEP_INIT, CODE_OK)

    # If force, first clear remote server folder
    if forceRun:
        files[0].removeRemoteFolderStructure()

    _step(0, "Checking, if results exists")
    _log(STEP_CHECK_RES, CODE_INIT)
    if cosmo.resultExists():
        _log(STEP_CHECK_RES, CODE_EXISTS)
        _step(0, "Results already exists.")
        _log(STEP_COSMO_DOWNLOAD, CODE_INIT)
        _step(8, "Downloading COSMO results...")
        cosmo.downloadResults()
        _log(STEP_COSMO_DOWNLOAD, CODE_OK)
        return
    
    _step(STEP_CHECK_RES, CODE_OK)

    #########################################################
    ######### STEP 1 ########################################
    ###### CREATE FOLDER STRUCTURE ON REMOTE SERVER #########
    #########################################################
    _log(STEP_REMOTE_FOLDER, CODE_INIT)
    _step(1, "Creating folder structure on remote server...")
    # Basic folder structure is the same for all substances
    files[0].checkRemoteFolderStructure()
    _step(1, "Done.")
    _log(STEP_REMOTE_FOLDER, CODE_OK)

    #########################################################
    ################# STEP 2 ################################
    ######## UPLOAD SDF FILES TO REMOTE SERVER ##############
    ######################################################### 

    _log(STEP_UPLOAD, CODE_INIT)
    _step(2, "Uploading SDF files to remote server...")
    skipped = uploaded = 0
    for f in files:
        f.remoteBasePath = files[0].remoteBasePath
        r = f.uploadSdfFile()
        if r is True:
            uploaded += 1
        else:
            skipped += 1
    _log(STEP_UPLOAD, CODE_OK)

    _step(2, "Total " + str(uploaded) + " files uploaded and " + str(skipped) + " skipped.")
    _step(2, "Done.")

    #########################################################
    #################### STEP 3 #############################
    ##### Create CUBY4 scripts file and upload them #########
    #########################################################
    _log(STEP_CUBY_CHECK_STATUS, CODE_INIT)
    _step(3, "### CUBY4 structure optimization ###")
    for f in files:
        f.checkOptimizationStatus()
    _log(STEP_CUBY_CHECK_STATUS, CODE_OK)

    _step(3, "Generating CUBY4 optimizing job files...")
    _log(STEP_CUBY_GENERATE, CODE_INIT)
    for f in files:
        if not reRun or (not f.optimizationResults and not f.optimizationRunning):
            f.getOptimizeInputs(ncpu=cpu, ram=ram, walltimeHs=limitHs,queue=queue)
    _log(STEP_CUBY_GENERATE, CODE_OK)
    _step(3, "Done.")

    _step(4, "Uploading cuby4 files to remote server...")
    
    skipped = uploaded = running = 0
    _log(STEP_CUBY_UPLOAD, CODE_INIT)
    for f in files: 
        if not reRun or (not f.optimizationResults and not f.optimizationRunning):
            r = f.uploadCubyFiles()
        if r == "running":
            running+= 1
        elif r == "skip":
            skipped += 1
        else :
            uploaded += 1

    _log(STEP_CUBY_UPLOAD, CODE_OK)

    _step(4, "Total " + str(uploaded) +" files uploaded, " + str(skipped) + " skipped and " + str(running) + " looks like running.")
    _step(4, "Done.")

    #########################################################
    ##################### STEP 4 ############################
    ###### Run CUBY4 optimization calculations ##############
    #########################################################
    _log(STEP_CUBY_RUN, CODE_INIT)
    _step(5, "Trying to run the jobs.")
    running = 0
    hasResult = 0
    for f in files: 
        if not reRun and f.optimizationHistoryRun and not f.optimizationResults:
            # Error occured
            _log(STEP_CUBY_RUN_JOB, CODE_ERR, f.name)
            continue

        if not f.readyToOptimize:
            if f.optimizationResults:
                hasResult += 1
                print(" - Structure", f.name, "optimization already computed. Skipping...")
            else: 
                running += 1
            continue

        jobID = f.runOptimization()
        if jobID:
            running += 1
            _log(STEP_CUBY_RUN_JOB, CODE_OK, str(f.name) + "/" + str(jobID))
    
    if(running != 0):
        _log(STEP_CUBY_RUN, CODE_IS_RUNNING, str(running) + "/" + str(len(files)))
    else:
        _log(STEP_CUBY_RUN, CODE_OK, str(len(files) - running) + "/" + str(len(files)))
        
    _step(5, "Done.")

    #########################################################
    ################ STEP 5 #################################
    ######## Copy COSMO files to one directory ##############
    #########################################################
    # Never run cosmo computation in reRun! There should be wrong membrane and temperature setting!
    if not reRun and running == 0 and hasResult >= len(files)/2:
        _step(6, "Copying prepared COSMO files for the COSMOmic/COSMOperm")
        _log(STEP_COSMO_PREPARE, CODE_INIT)
        cosmoFiles = list()
        for f in files:
            if f.optimizationResults:
                f.copyCosmoFile()
                cosmoFiles.append(f)

        _step(6, "Done.")

        #########################################################
        ################# STEP 7 ################################
        #### Generate COSMOperm/COSMOmic job files ##############
        #########################################################
        _step(7, "Making COSMOperm/COSMOmic job files.")
        _log(STEP_COSMO_PREPARE, CODE_OK)
        _log(STEP_COSMO_RUN, CODE_INIT)
        cosmo.prepareRun(cosmoFiles)
        # Get COSMO run state
        isRunning, hasResults = cosmo.getState()
        if isRunning:
            _log(STEP_COSMO_RUN, CODE_IS_RUNNING)
        if hasResults:
            _log(STEP_COSMO_RUN, CODE_HAS_RESULT)
        _step(7, "COSMO run files generated. Uploading...")
        if not cosmo.uploadRunFiles():
            _log(STEP_COSMO_RUN, CODE_EXISTS)
        _step(7, "Trying to run COSMO computation...")
        jobId = cosmo.run()
        if jobId:
            _log(STEP_COSMO_RUN, CODE_OK, jobId)
        _step(7, "Done.")

        ##########################################################
        ################### STEP 8 ###############################
        ####### Download computed COSMO files ####################
        ##########################################################
        _log(STEP_COSMO_DOWNLOAD, CODE_INIT)
        if cosmo.cosmoResults:
            _step(8, "Downloading COSMO results...")
            cosmo.downloadResults()
            _log(STEP_COSMO_DOWNLOAD, CODE_OK)
        else:
            _log(STEP_COSMO_DOWNLOAD, CODE_WAITING)
            _step(8, "Waiting for COSMO results.")

    _step(10, "All steps done. Exiting...")


class COSMO:
    QUEUE_ELIXIR = "elixircz@elixir-pbs.elixir-czech.cz"
    QUEUE_METACENTRUM = "default@meta-pbs.metacentrum.cz"
    QUEUE_CERIT = "@cerit-pbs.cerit-sc.cz"
    
    def __init__(self, sshClient, forceRun, type, temperature, membrane, membraneName, queue = "elixir", files = []): 
        self.files = files
        self.name = ""
        self.force = forceRun
        self.ssh = sshClient
        self.type = type # perm, mic
        self.temperature = temperature
        self.membranePath = membrane
        self.membraneName = membraneName
        if "elixir" in str(queue):
            self.queue = self.QUEUE_ELIXIR
        elif "cerit" in str(queue):
            self.queue = self.QUEUE_CERIT
        else:
            self.queue = self.QUEUE_METACENTRUM

        self.cosmoINP = self.cosmoJob = None
        self.cosmoRunning = self.cosmoResults = False
        self.readyToRun = True

    def prepareRun(self, sdfFiles):
        self.files = sdfFiles
        # Prepare cosmo input
        self.cosmoINP = self.genCosmoInput()
        self.cosmoJob = self.genCosmoJob()

    def getFolderName(self):
        return self.type + "_" + str(self.membraneName).replace(" ", "-") + "_" + str(self.temperature).replace(".", ",")

    def getState(self):
        running, hasResult = False, False
        file = self.files[0]
        # Check, if job is potentialy running
        folder = self.getFolderName()
        in_path = str(file.getCosmoInputPath()).rstrip("/") + "/" + folder
        self.ssh.shell_exec("mkdir -p " + in_path)
        # Check if file already exists
        existing = self.ssh.sftp.listdir(in_path)
        if type(existing) is not list:
            existing = list()
        # Checking, if job is not running
        for f in existing:
            f=str(f)
            if f.endswith(".run"):
                running = True

        out_path = file.remoteBasePath + "04-COSMO_RESULTS/" + str(self.getFolderName()) + "/"
        self.ssh.shell_exec("mkdir -p " + out_path)
        existing = self.ssh.sftp.listdir(out_path)
        if type(existing) is not list:
            existing = list()

        existing = list(filter(lambda f: f.endswith(".tab") or f.endswith(".xml"), existing))

        if len(existing) > 1:
            hasResult = True

        self.cosmoRunning = running
        self.cosmoResults = hasResult
        return running, hasResult

    def uploadRunFiles(self):
        if self.cosmoResults and not self.force:
            self.readyToRun = False
            print(" --- Cosmo already computed. Skipping.")
            return

        if self.cosmoRunning and not self.force:
            self.readyToRun = False
            print(" --- Cosmo looks like running. Skipping.")
            return

        f = self.files[0]
        target = f.getCosmoInputPath()
        sftp = self.ssh.sftp
        folder = self.getFolderName()
        import tempfile

         # Check if file already exists
        self.ssh.shell_exec("mkdir -p " + target)
        existing = sftp.listdir(target)
        if self.membraneName not in existing:
            existing = list()
        else:
            existing = sftp.listdir(target.rstrip("/") + "/" + folder)
            if type(existing) is not list:
                existing = list()

        target = target.rstrip("/") + "/" + folder
        self.ssh.shell_exec("mkdir -p " + target)

        tmpJob = tempfile.NamedTemporaryFile(delete=False)
        tmpInp = tempfile.NamedTemporaryFile(delete=False)
        skipped = False
        try:
            with open(tmpJob.name, "w") as tY:
                tY.write(self.cosmoJob)
            with open(tmpInp.name, "w") as tY:
                tY.write(self.cosmoINP)
            # Upload
            if "cosmo.inp" not in existing or self.force:
                sftp.put(tmpInp.name, target + "/" + "cosmo.inp")
            else: 
                skipped = True
            if "cosmo.job" not in existing or self.force:
                sftp.put(tmpJob.name, target + "/" + "cosmo.job")
            else: 
                skipped = True
            # Copy micelle file
            sftp.put(self.membranePath, target + "/" + "micelle.mic")
        finally:
            tmpJob.close()
            tmpInp.close()
            os.unlink(tmpJob.name)
            os.unlink(tmpInp.name)

        if skipped:
            return "skip"
        return True

    def downloadResults(self):
        if not self.cosmoResults:
            return

        f = self.files[0]
        sftp = self.ssh.sftp

        out_path = f.remoteBasePath + "04-COSMO_RESULTS/" + str(self.getFolderName()) + "/"
        existing = sftp.listdir(out_path)

        if type(existing) is not list or not len(existing):
            _log(STEP_COSMO_DOWNLOAD, CODE_ERR)
            _err("Output folder doesnt contain any file...")

        existing = existing if type(existing) is list else list()

        localOutPath = f.folder + "COSMO/" + str(self.getFolderName()) + "/"
        if not os.path.exists(localOutPath):
            os.makedirs(localOutPath)

        for fp in existing:
            p = out_path + fp
            sftp.get(p, localOutPath + fp)

    def resultExists(self):
        f = self.files[0]
        localOutPath = f.folder + "COSMO/" + str(self.getFolderName()) + "/"
        if not os.path.exists(localOutPath):
            os.makedirs(localOutPath)
        files = os.listdir(localOutPath)
        existing = list(filter(lambda f: f.endswith(".tab") or f.endswith(".xml"), files))
        return len(existing) > 0

    def run(self):
        if not self.readyToRun:
            return

        f = self.files[0]
        in_folder = f.getCosmoInputPath() + "/" + str(self.getFolderName())
        self.ssh.shell_exec("cd " + in_folder)

        output = self.ssh.shell_exec(self.ssh.qsub + " cosmo.job")
        if len(output) != 1 or not re.match(r"^\d+", output[0]):
            print(output)
            _err("Cannot run the job.", sshClient=self.ssh)
        jobID = re.sub(r'\..*$', "", output[0])
        # Create log to inform, that job was run
        self.ssh.shell_exec("echo 'running' > " + jobID + ".run")
        print(" --- OK. JobID:", jobID)
        return jobID
    
    def genCosmoJob(self):
        f = self.files[0]
        COSMO_INPUT = f.getCosmoInputPath() + "/" + str(self.getFolderName())
        OUTPATH = f.remoteBasePath + "04-COSMO_RESULTS/" + str(self.getFolderName()) + "/"

        return f"""#!/bin/bash
#PBS -q {self.queue}
#PBS -N MMDB_COSMO_{self.type}_{f.name}
#PBS -l select=1:ncpus=10:mem=5gb:scratch_local=5gb
#PBS -l walltime=10:00:00
trap 'clean_scratch' TERM EXIT
cd $SCRATCHDIR || exit 1

COSMO=/storage/praha5-elixir/home/xjur2k/COSMOlogic/COSMOthermX18/COSMOtherm/BIN-LINUX/cosmotherm
INP={COSMO_INPUT}
OUT={OUTPATH}

cp $INP/cosmo.inp .
cp $INP/micelle.mic .

$COSMO cosmo.inp

rm -f $INP/*.run

mkdir -p $OUT

cp -r * $OUT || export CLEAN_SCRATCH=false
        """

    def genCosmoInput(self):
        content = """ctd = BP_TZVPD_FINE_18.ctd cdir = "/storage/praha5-elixir/home/xjur2k/COSMOlogic/COSMOthermX18/COSMOtherm/CTDATA-FILES" ldir = "/storage/praha5-elixir/home/xjur2k/COSMOlogic/COSMOthermX18/licensefiles"
rmic=micelle.mic"""
        if self.type != "mic": # Cosmo perm
            content += " unit notempty wtln ehfile"

        l = self.files.copy()
        first = l.pop(0)
        content += " accc \n! " + first.name + " conformer computation !\n"
        # Add files

        self.name = first.name

        content += "f = " + str(first.name) + ".ccf fdir=" + str(first.getCosmoInputPath()) + " Comp = " + str(first.name)

        if len(l):
            content += " ["
            for f in l:
                content += "\n" + "f = " + str(f.name) + ".ccf fdir=" + str(f.getCosmoInputPath())
            content += " ]"

        content += "\n"

        if self.type == "mic":
            content += "tc=" + str(self.temperature) + " x_pure=Micelle"
        else:
            content += "tc=" + str(self.temperature) + " micelle permeability centersig2 rmic=micelle.mic"

        return content

class SDFFile:
    # Constants
    SERVER_FOLDER_PREFIX = "~/.MolMeDB/COSMO/"
    LAST_FOLDER = "conformers"
    FILE_FOLDERS = [
        "01-INPUT",
        "02-OPTIMIZE",
        "03-COSMO_INPUT",
        "04-COSMO_RESULTS"
    ]
    
    SCRIPTPATH_PS = "[SCRIPT_PATH]"
    SDFPATH_PS = "[SDFPATH_PS]"
    LOGPATH_PS = "[LOGPATH_PS]"
    

    def __init__(self, path, sshClient=None, charge = 0, forceRun=False, reRun = False):
        self.path = str(path).strip()
        self.sshClient = sshClient
        self.folder, self.fileName = self.getFolder(path)
        self.remoteBasePath = None
        self.forceRun = forceRun
        self.name = re.sub(r"\.sdf", "", self.fileName)
        self.cuby = CUBY4()
        self.readyToOptimize = False
        self.charge = charge
        self.reRun = reRun

        self.optimizeScripts = None

        self.optimizationRunning = self.optimizationResults = self.optimizationHistoryRun = False

        if not os.path.exists(path):
            _err("Target file not exists.")

    def getCosmoInputPath(self):
        return self.remoteBasePath + self.FILE_FOLDERS[2]

    def getFolder(self, path):
        path = str(path)
        # Check if contains file with suffix
        lastPos = path.rfind("/")
        if path.rfind(".") > lastPos:
            file = path[lastPos+1:].strip()
            path = path[:lastPos] + "/"
        else:
            file = None
            path = path.strip("/") + "/"
        return path, file

    def removeRemoteFolderStructure(self):
        path = str(self.folder)
        index = path.find(self.LAST_FOLDER)
        if not index:
            _log(STEP_REMOTE_FOLDER, CODE_ERR)
            _err("Invalid input folder structure.", self.sshClient)
        path = path[index+(self.LAST_FOLDER.__len__())+1:]
        path = path.strip("/") + "/"
        if not path:
            _log(STEP_REMOTE_FOLDER, CODE_ERR)
            _err("Invalid input folder structure.", self.sshClient)
        # Remove file structure
        path = self.SERVER_FOLDER_PREFIX + path
        self.sshClient.shell_exec('rm -rf ' + path)

    def checkRemoteFolderStructure(self):
        try:
            path = str(self.folder)
            index = path.find(self.LAST_FOLDER)
            if not index:
                _log(STEP_REMOTE_FOLDER, CODE_ERR)
                _err("Invalid input folder structure.", self.sshClient)
            path = path[index+(self.LAST_FOLDER.__len__())+1:]
            path = path.strip("/") + "/"
            if not path:
                _log(STEP_REMOTE_FOLDER, CODE_ERR)
                _err("Invalid input folder structure.", self.sshClient)
            # Make file structure
            path = self.SERVER_FOLDER_PREFIX + path
            for fold in self.FILE_FOLDERS:
                response = self.sshClient.shell_exec("mkdir -p " + path + fold)
                if len(response) != 0:
                    return False, response.join("\n")
            # Save remote base path for next usage
            out = self.sshClient.shell_exec("readlink -f " + path)

            if out and len(out) and str(out[0]).startswith("/"):
                self.remoteBasePath = str(out[0].rstrip("/")) + "/"
                self.remoteBasePath = self.remoteBasePath.replace("/mnt/storage-", "/storage/")
                self.remoteBasePath = self.remoteBasePath.replace("/nfs4", "")
            else:
                _log(STEP_REMOTE_FOLDER, CODE_ERR)
                _err("Cannot obtain full remote base path.", self.sshClient)
            return True
        except Exception as e:
            _log(STEP_REMOTE_FOLDER, CODE_ERR)
            print(e)
            _err("Exception occured during making file structure.", self.sshClient)

    def uploadSdfFile(self):
        try:
            if self.remoteBasePath == "":
                _log(STEP_UPLOAD, CODE_ERR)
                _err("Remote folder seems to not exist.", self.sshClient)

            target = str(self.remoteBasePath) + self.FILE_FOLDERS[0] + "/"
            # Check if file already exists
            existing = self.sshClient.sftp.listdir(target)
            if existing and len(existing) and self.name in existing and not self.forceRun:
                return None
            print(" - Uploading ", self.name , "file.")
            self.sshClient.sftp.put(self.path, target + self.fileName, confirm=False)
        except Exception as e:
            _log(STEP_UPLOAD, CODE_ERR)
            _err("Exception occured during uploading files..", self.sshClient)
        return True

    def checkOptimizationStatus(self):
        running, hasResult, wasRun = False, False, False
        # Check, if job is potentialy running
        out_folder = self.remoteBasePath + self.FILE_FOLDERS[1] + "/" + self.name
        self.sshClient.shell_exec("mkdir -p " + out_folder)
        # Check if file already exists
        existing = self.sshClient.sftp.listdir(out_folder)
        if type(existing) is not list:
            existing = list()
        # Checking, if job is not running
        for f in existing:
            f=str(f)
            if f.endswith(".run"):
                running = True
            if f == "OUTPUT":
                wasRun = True
                # Check if content is valid
                l = self.sshClient.sftp.listdir(out_folder + "/OUTPUT")
                l = list(filter(lambda f: f.startswith("job_step"), l))
                l.sort()
                if not len(l):
                    hasResult = False
                else:
                    l = l[-1] # Get last result folder
                    l = self.sshClient.sftp.listdir(out_folder + "/OUTPUT/" + l)
                    if "out.ccf" in l:
                        hasResult = True

        self.optimizationRunning = running
        self.optimizationResults = hasResult
        self.optimizationHistoryRun = wasRun

        return running, hasResult


    def getOptimizeInputs(self, ncpu=8, ram=32, walltimeHs=10,queue="elixir"):
        self.cuby.generate(self, ncpu=ncpu, ram=ram, walltimeHs=walltimeHs, queue=queue, includeTurobmolePreComputation=False)

    def uploadCubyFiles(self):
        if self.optimizationResults and not self.forceRun:
            self.readyToOptimize = False
            print(" --- Job", self.name, "already computed. Skipping.")
            return

        if not self.cuby.filesContent:
            _err("Nothing to upload for file", self.name, ".", self.sshClient)

        import tempfile
        print(" - Uploading optimizing job files for", self.name)
        # Create folder for each structure
        out_folder = self.remoteBasePath + self.FILE_FOLDERS[1] + "/" + self.name
        self.sshClient.shell_exec("mkdir -p " + out_folder)
        sdf_folder = self.remoteBasePath + self.FILE_FOLDERS[0]
        log_foler = self.remoteBasePath.split("/COSMO/")[0] + "/COSMO/LOGS/RUNS/"
        
        # Check if file already exists
        existing = self.sshClient.sftp.listdir(out_folder)
        if type(existing) is not list:
            existing = list()

        # Checking, if job is not running
        if self.optimizationRunning and not self.forceRun:
            print(" --- Job", self.name, "looks like running. Skipping.")
            self.readyToOptimize = False
            return "running"
        # Fill variables with paths
        contentYAML = str(self.cuby.getYaml())
        contentjob = str(self.cuby.getJob())
        contentjob = contentjob.replace(self.SCRIPTPATH_PS, out_folder)
        contentjob = contentjob.replace(self.SDFPATH_PS, sdf_folder)
        contentjob = contentjob.replace(self.LOGPATH_PS, log_foler)
        # Upload both to the remote server
        # Create temporary file and upload to server
        tmpYaml = tempfile.NamedTemporaryFile(delete=False)
        tmpJob = tempfile.NamedTemporaryFile(delete=False)
        skipped = False
        self.readyToOptimize = True
        try:
            with open(tmpYaml.name, "w") as tY:
                tY.write(contentYAML)
            with open(tmpJob.name, "w") as tY:
                tY.write(contentjob)
            # Upload
            if self.name + ".yaml" not in existing or self.forceRun or self.reRun:
                self.sshClient.sftp.put(tmpYaml.name, out_folder + "/" + self.name + ".yaml")
            else: 
                skipped = True
            if self.name + ".job" not in existing or self.forceRun or self.reRun:
                self.sshClient.sftp.put(tmpJob.name, out_folder + "/" + self.name + ".job")
            else: 
                skipped = True
        finally:
            tmpJob.close()
            tmpYaml.close()
            os.unlink(tmpJob.name)
            os.unlink(tmpYaml.name)

        self.sshClient.shell_exec("cd ~/")

        if skipped:
            return "skip"
        return True

    def runOptimization(self):
        try:
            print(" - Trying to run:", self.name)
            out_folder = self.remoteBasePath + self.FILE_FOLDERS[1] + "/" + self.name
            self.sshClient.shell_exec("cd " + out_folder)
            # Remove output folder if present
            self.sshClient.shell_exec("rm -r OUTPUT")
            # Run new job
            scriptFile = self.name + ".job"
            output = self.sshClient.shell_exec(self.sshClient.qsub + " " + scriptFile)
            if len(output) != 1 or not re.match(r"^\d+", output[0]):
                print(output)
                _log(STEP_CUBY_RUN, CODE_ERR)
                _err("Cannot run the job.", sshClient=self.sshClient)
            jobID = re.sub(r'\..*$', "", output[0])
            # Create log to inform, that job was run
            self.sshClient.shell_exec("echo 'running' > " + jobID + ".run")
            self.optimizeJobID = jobID
            print(" --- OK. JobID:", jobID)
            return jobID
        except Exception as e: 
            _log(STEP_CUBY_RUN, CODE_ERR)
            print(e)
            _err("Exception occured.", self.sshClient)

    def copyCosmoFile(self):
        # Check, if job is potentialy running
        out_folder = self.remoteBasePath + self.FILE_FOLDERS[1] + "/" + self.name + "/OUTPUT"
        existing = self.sshClient.sftp.listdir(out_folder)
        if type(existing) is not list:
            existing = list()
        # Check if content is valid
        l = self.sshClient.sftp.listdir(out_folder)
        l = list(filter(lambda f: f.startswith("job_step"), l))
        l.sort()
        if not len(l):
            _err("Cannot find cosmo result file for structure " + self.name + ".", self.sshClient)
        else:
            l = l[-1] # Get last result folder
            l1 = self.sshClient.sftp.listdir(out_folder + "/" + l)
            if "out.ccf" not in l1:
                _err("Cannot find cosmo result file for structure " + self.name + ".", self.sshClient)
            out = self.sshClient.shell_exec("cp " + out_folder + "/" + l + "/out.ccf " + self.remoteBasePath + self.FILE_FOLDERS[2] + "/" + self.name + ".ccf")
            if len(out) and str(out[0]).startswith(""):
                print(out)
                _err("Cannot copy result file.", self.sshClient)



class SSHClient:
    def __init__(self, username, password, host, port=22):
        self.username = username
        self.password = password
        self.host = host
        self.shell = None
        self.qsub = None
        self.ssh = None
        self.sftp = None

        self.connect()

    def connect(self):
        self.ssh = paramiko.SSHClient()
        self.ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        # Connect
        self.ssh.connect(
            self.host, 
            username=self.username, 
            password=self.password
        )
        import time
        # init shell
        self.shell = self.ssh.invoke_shell()
        self.shell_exec("clear")
        # Switch to bash
        self.shell_exec("bash")
        if not self.kinit():
            self.close()
            _err("Cannot initialize kerberos token.")
        # Get qsub path
        for i in range(3):
            output = self.shell_exec("which qsub")
            if i < 2 and (not len(output) or not str(output[0]).startswith("/")):
                time.sleep(2)

        if not len(output) or not str(output[0]).startswith("/"):
            self.close()
            _err("Cannot get `qsub` remote path")
        self.qsub = output[0]
        self.sftp = self.ssh.open_sftp()

    def kinit(self):
        out = self.shell_exec("kinit")
        if not len(out):
            return True
        # Set password if required
        if "password" in out[0].lower():
            out = self.shell_exec(self.password, clear=False)
        return True

    def close(self):
        if self.sftp: self.sftp.close()
        if self.ssh: self.ssh.close()

    def shell_exec(self, command, clear=True):
        if clear:
            pass
            # self.shell.send("clear\n")
        # Execute command
        self.shell.send(command+"\n")
        # Read output
        out = ""
        # sleep is essential, recv_ready returns False without sleep
        time.sleep(1)
        while self.shell.recv_ready():
            out += str(self.shell.recv(2048))

        out = out.replace("\\r", "")
        out = out.replace("\\t", "")
        out = out.replace("\\x1b", "\x1b")
        out = out.split('\\n')
        if len(out) < 2:
            return []
        out = [ansi_escape.sub('', t) for t in out]
        if "password" in str(out[-1]).lower():
            return [str(out[-1])]
        out.pop()

        if len(command) > 10:
            command = command[:10]

        if len(out) and str(command).lower() in str(out[0]).lower():
            out.pop(0)
        return out

def get_conformer_folder(id_fragment, id_ion):
    group = int(id_fragment / 10000)
    group = group * 10000
    group = str(group) + "-" + str(group+10000)
    return "media/files/conformers/" + group + "/" + str(id_fragment) + "/" + str(id_ion) + "/"


####### Read params #######
import sys, getopt

_log(STEP_INPUT_CHECK, CODE_INIT)

params = sys.argv[1:]
help = """ -- Invalid script setting. --

Run cosmo_pipeline.py with the following parameters:
 --ions <path>: List of ions to process in following format: <id_fragment/id_ion/charge> separated by space. Example: '1/110/0 2/30/-2'
 --host <url>: Remote server URL address, e.g. elmo.metacentrum.cz
 --username <string>: Remote server access username.
 --password <string>: Remote server access password.
 --cpu <int>: Number of threads/cpus used for optimization process.
 --ram <int>: Number of GB ram used for optimization process.
 --limit <int>: Number of hours used as upper limit for optimization process.
 --cosmo <mic/perm>: Type of cosmomic computation to run.
 --temp <float>: Temperature in °C to run COSMOperm/COSMImic with.
 --membrane <path>: Absolute path of file containing COSMO membrane definition, e.g. ../cosmo/Caffeine/DOPC_T25/..
 --membName <string>: Used for a proper folder structure definition of remote server.  
 --queue <elixir/metacentrum/cerit>: Which queue to use for job?
 --force <true/false>: When included, all steps will be run again - No check for result existence.

Example:
cosmo_pipeline.py --base /path/to/sdf/MM00040/neutral/ --cpu 8 --ram 32 --limit 10 --cosmo perm --temp 25 --membrane /path/to/membrane/DOPC.mic --membraneName DOPC
"""

ions = charge = usernam = password = host = reRun = username = password = cpu = ram = limitHs = membrane = membraneName = temperature= cosmoType=queue=forceRun=None

try:
    opts, args = getopt.getopt(params, "", [
        "ions =",
        "host =",
        "cpu =",
        "ram =",
        "limit =",
        "cosmo =",
        "temp =",
        "membrane =",
        "membName =",
        "force =",
        "queue =",
        "username =",
        "password =",
        "reRun =",
    ])
except Exception as e:
    print("Invalid parameter set.")
    print(e)
    print(help)
    _log(STEP_INPUT_CHECK, CODE_ERR)
    exit(2)

try:
    for opt, arg in opts:
        opt = str(opt).strip()
        if opt == "--ions":
            t = arg.split()
            ions = list()
            if len(t) < 1:
                raise Exception("No ion specified.")
            for ion in t:
                t2 = ion.split("/")
                if len(t2) != 3:
                    raise Exception("Invalid setting of ion: " + str(ion))
                ions.append({
                    "id_fragment": t2[0],
                    "id_ion": t2[1],
                    "charge": t2[2]
                })
        elif opt == "--cpu":
            cpu = int(arg)
            if cpu < 1 or cpu > 64:
                raise Exception("Number of CPUs must be number in 1-64 range.")
        elif opt == "--host":
            host = arg
        elif opt == "--ram":
            ram = int(arg)
            if ram < 1 or ram > 512:
                raise Exception("Number or [GB] RAM must be number in 1-512 range.")
        elif opt == "--limit":
            limitHs = int(arg)
            if limitHs < 0 or limitHs > 500:
                raise Exception("Upper limit time must be number in 1-500 range.")
        elif opt == "--cosmo":
            cosmoType = arg
            if cosmoType not in ["mic", "perm"]: raise Exception("Invalid COSMO type value.")
        elif opt == "--temp":
            temperature = float(arg)
        elif opt == "--membrane":
            membrane = arg
            if not os.path.isfile(arg): raise Exception("Membrane file seems to not exist. Please, check the path.")
        elif opt == "--membName":
            membraneName = arg
        elif opt == "--queue":
            queue = arg
        elif opt == "--username":
            username = arg
        elif opt == "--password":
            password = arg
        elif opt == "--reRun":
            if str(arg).lower() == "true": reRun = True
            else: reRun = False
        elif opt == "--force":
            if str(arg).lower() == "true": forceRun = True
            else: forceRun = False

except Exception as err:
    print("\n!! " + str(err) + " !!\n")
    print(help)
    _log(STEP_INPUT_CHECK, CODE_ERR)
    exit(2)

if None in [ions, cpu, ram, limitHs, cosmoType, temperature, membrane, membraneName, queue, username, password]:
    print("Missing parameters.")
    print([ions, cpu, ram, limitHs, cosmoType, temperature, membrane, membraneName, queue, username, password])
    print(help)
    _log(STEP_INPUT_CHECK, CODE_ERR)
    exit(2)

# Iterate over ions and run script
for ion in ions:
    id_fragment, id_ion, charge = (int(ion['id_fragment']), int(ion['id_ion']), int(ion['charge']))
    # Try to find path and check, if SDF files exists
    path = get_conformer_folder(id_fragment, id_ion)
    if not os.path.exists(path):
        print("\n!! Path `" + path + "` not exists.")
        exit()

    print("\nJOB: " + str(id_fragment) + "/" + str(id_ion) + "/" + str(charge) + "\n")

    # Process ion
    __main__(
        path, 
        host, 
        username, 
        password,
        charge=charge,
        cpu=cpu,
        ram=ram,
        limitHs=limitHs,
        membrane=membrane,
        membraneName=membraneName,
        temp=temperature,
        cosmo=cosmoType,  
        queue=queue,
        forceRun=forceRun,
        reRun=reRun)
    
print("\n#$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$#\n") # Log separator
    
if sshClient != None:
    sshClient.close()

