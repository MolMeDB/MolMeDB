import re, os, time
from general.file import File
import paramiko
from cosmo.cuby4 import CUBY4

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

paramiko.util.log_to_file("paramiko.log")

def _err(text, sshClient = None):
    print("Error:", text)
    if sshClient: sshClient.close()
    exit()

def _step(num, text):
    print("STEP", str(num) + ":", text)



def __main__(confPath, host, username, password, cpu=8, ram=32, limitHs=10, membrane=None, membraneName = None, temp=25, queue="elixir", cosmo="perm", forceRun = False):
    # Must be folder of conformers
    confPath, file = File.getFolder(confPath)
    # Check if folder exists
    if not os.path.exists(confPath):
        _err("Target folder not exists.")

    if not os.path.exists(membrane):
        _err("Membrane file not exists.")

    if cosmoType not in ["perm", "mic"]:
        _err("Invalid cosmo type.")

    if file is None:
        # Check if folder contains some conformers
        files = os.listdir(confPath)
        files = list(filter(lambda f: f.endswith(".sdf"), files))
        # Add prefixes
        files = [os.path.abspath(confPath + f) for f in files]
    elif not os.path.exists(file):
        _err("SDF file", file, "not exists.")
    else:
        files = [file]

    if len(files) == 0:
        _err("No SDF files found.")


    #########################################################
    #################### STEP 0 #############################
    ############ Connect to the remote server ###############
    _step(0, "SSH connection inizialize.")
    sshClient = SSHClient(username=username, password=password,host=host, port=22)
    
    # Init SDF File instances
    files = [SDFFile(f, sshClient=sshClient, forceRun=forceRun) for f in files]
    _step(0, "Done")

    cosmo = COSMO(
        sshClient=sshClient, 
        forceRun = forceRun, 
        type=cosmoType,
        temperature=temperature,
        membrane=membrane,
        membraneName=membraneName,
        queue=queue)
    #########################################################
    ######### STEP 1 ########################################
    ###### CREATE FOLDER STRUCTURE ON REMOTE SERVER #########
    #########################################################
    _step(1, "Creating folder structure on remote server...")
    # Basic folder structure is the same for all substances
    files[0].checkRemoteFolderStructure()
    _step(1, "Done.")

    #########################################################
    ################# STEP 2 ################################
    ######## UPLOAD SDF FILES TO REMOTE SERVER ##############
    ######################################################### 
    _step(2, "Uploading SDF files to remote server...")
    skipped = uploaded = 0
    for f in files:
        r = f.uploadSdfFile()
        if r is True:
            uploaded += 1
        else:
            skipped += 1

    _step(2, "Total " + str(uploaded) + " files uploaded and " + str(skipped) + " skipped.")
    _step(2, "Done.")

    #########################################################
    #################### STEP 3 #############################
    ##### Create CUBY4 scripts file and upload them #########
    #########################################################
    _step(3, "### CUBY4 structure optimization ###")
    for f in files:
        f.checkOptimizationStatus()

    _step(3, "Generating CUBY4 optimizing job files...")
   
    for f in files:
        f.getOptimizeInputs(ncpu=cpu, ram=ram, walltimeHs=limitHs,queue=queue)

    _step(3, "Done.")

    _step(4, "Uploading cuby4 files to remote server...")
    skipped = uploaded = running = 0
    for f in files: 
        r = f.uploadCubyFiles()
        if r == "running":
            running+= 1
        elif r == "skip":
            skipped += 1
        else :
            uploaded += 1

    _step(4, "Total " + str(uploaded) +" files uploaded, " + str(skipped) + " skipped and " + str(running) + " looks like running.")
    _step(4, "Done.")

    #########################################################
    ##################### STEP 4 ############################
    ###### Run CUBY4 optimization calculations ##############
    #########################################################
    _step(5, "Trying to run the jobs.")
    for f in files: 
        if not f.readyToOptimize:
            if f.optimizationResults:
                print(" - Structure", f.name, "optimization already computed. Skipping...")
            continue
        f.runOptimization()

    _step(5, "Done.")

    #########################################################
    ################ STEP 5 #################################
    ######## Copy COSMO files to one directory ##############
    #########################################################

    _step(6, "Copying prepared COSMO files for the COSMOmic/COSMOperm")
    cosmoReady = 0
    for f in files:
        if f.optimizationResults:
            f.copyCosmoFile()
            cosmoReady+=1

    _step(6, "Done.")


    #########################################################
    ################# STEP 7 ################################
    #### Generate COSMOperm/COSMOmic job files ##############
    #########################################################
    _step(7, "Making COSMOperm/COSMOmic job files.")
    # Check, if all results are copied
    if cosmoReady != len(files):
        _step(7, "Cosmo computation skipped. Computed " + str(cosmoReady) + "/" + str(len(files) + " optimizations."))
    else:
        cosmo.prepareRun(files)
        # Get COSMO run state
        cosmo.getState()
        _step(7, "COSMO run files generated. Uploading...")
        cosmo.uploadRunFiles()
        _step(7, "Trying to run COSMO computation...")
        cosmo.run()
        _step(7, "Done.")

    _step(10, "All steps done. Exiting...")
    sshClient.close()


class COSMO:
    QUEUE_ELIXIR = "elixircz@elixir-pbs.elixir-czech.cz"
    QUEUE_METACENTRUM = "default@meta-pbs.metacentrum.cz"
    QUEUE_CERIT = "cerit-pbs.cerit-sc.cz"
    
    def __init__(self, sshClient, forceRun, type, temperature, membrane, membraneName, queue = "elixir"): 
        self.files = []
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
    
    def genCosmoJob(self):
        f = self.files[0]
        COSMO_INPUT = f.getCosmoInputPath() + "/" + str(self.getFolderName())
        OUTPATH = f.remoteBasePath + "04-COSMO_RESULTS/" + str(self.getFolderName()) + "/"

        return f"""#!/bin/bash
#PBS -q {self.queue}
#PBS -N COSMO_{self.type}_{f.name}
#PBS -l select=1:ncpus=10:mem=5gb:scratch_local=5gb
#PBS -l walltime=5:00:00
trap 'clean_scratch' TERM EXIT
cd $SCRATCHDIR || exit 1

COSMO=/storage/praha5-elixir/home/xjur2k/COSMOlogic/COSMOthermX18/COSMOtherm/BIN-LINUX/cosmotherm
INP={COSMO_INPUT}
OUT={OUTPATH}

cp $INP/cosmo.inp .
cp $INP/micelle.mic .

$COSMO cosmo.inp

rm $INP/*.run

mkdir -p $OUT

cp -r * $OUT || export CLEAN_SCRATCH=false
        """

    def genCosmoInput(self):
        content = """ctd = BP_TZVPD_FINE_18.ctd cdir = "/storage/praha5-elixir/home/xjur2k/COSMOlogic/COSMOthermX18/COSMOtherm/CTDATA-FILES" ldir = "/storage/praha5-elixir/home/xjur2k/COSMOlogic/COSMOthermX18/licensefiles"
rmic=micelle.mic"""
        if self.type != "mic": # Cosmo perm
            content += " unit notempty wtln ehfile"

        content += "\n"
        # Add files
        l = self.files.copy()
        first = l.pop(0)

        self.name = first.name

        content += "f = " + str(first.name) + ".ccf fdir=" + str(first.getCosmoInputPath()) + " Comp = " + str(first.name)

        if len(l):
            content += "[\n"
            for f in l:
                content += "f = " + str(f.name) + ".ccf fdir=" + str(f.getCosmoInputPath()) + "\n"
            content += "]"

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
    

    def __init__(self, path, sshClient=None, forceRun=False):
        self.path = path
        self.sshClient = sshClient
        self.folder, self.fileName = self.getFolder(path)
        self.remoteBasePath = None
        self.forceRun = forceRun
        self.name = re.sub(r"\.sdf", "", self.fileName)
        self.cuby = CUBY4()
        self.readyToOptimize = False

        self.optimizeScripts = None

        self.optimizationRunning = self.optimizationResults = False

        if not os.path.exists(path):
            _err("Target file not exists.")

    def getCosmoInputPath(self):
        return self.remoteBasePath + self.FILE_FOLDERS[2]

    def getFolder(self, path):
        path = str(path)
        # Check if contains file with suffix
        lastPos = path.rfind("/")
        if path.rfind(".") > lastPos:
            file = path[lastPos+1:]
            path = path[:lastPos] + "/"
        else:
            file = None
            path = path.strip("/") + "/"
        return path, file

    def checkRemoteFolderStructure(self):
        try:
            path = str(self.folder)
            index = path.find(self.LAST_FOLDER)
            if not index:
                _err("Invalid input folder structure.", self.sshClient)
            path = path[index+(self.LAST_FOLDER.__len__())+1:]
            path = path.strip("/") + "/"
            if not path:
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
            else:
                _err("Cannot obtain full remote base path.", self.sshClient)
            return True
        except:
            _err("Exception occured during making file structure.", self.sshClient)

    def uploadSdfFile(self):
        try:
            if self.remoteBasePath == "":
                _err("Remote folder seems to not exist.", self.sshClient)

            target = str(self.remoteBasePath + self.FILE_FOLDERS[0]) + "/"
            # Check if file already exists
            existing = self.sshClient.sftp.listdir(target)
            if existing and len(existing) and self.name in existing and not self.forceRun:
                return None
            print(" - Uploading ", self.name , "file.")
            self.sshClient.sftp.put(self.path, target + self.fileName, confirm=False)
        except:
            _err("Exception occured during uploading files..", self.sshClient)
        return True

    def checkOptimizationStatus(self):
        running, hasResult = False, False
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

        return running, hasResult


    def getOptimizeInputs(self, ncpu=8, ram=32, walltimeHs=10,queue="elixir"):
        self.cuby.generate(self, ncpu=ncpu, ram=ram, walltimeHs=walltimeHs, queue=queue)

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
            if self.name + ".yaml" not in existing or self.forceRun:
                self.sshClient.sftp.put(tmpYaml.name, out_folder + "/" + self.name + ".yaml")
            else: 
                skipped = True
            if self.name + ".job" not in existing or self.forceRun:
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
                _err("Cannot run the job.", sshClient=self.sshClient)
            jobID = re.sub(r'\..*$', "", output[0])
            # Create log to inform, that job was run
            self.sshClient.shell_exec("echo 'running' > " + jobID + ".run")
            self.optimizeJobID = jobID
            print(" --- OK. JobID:", jobID)
        except: 
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
        # init shell
        self.shell = self.ssh.invoke_shell()
        self.shell_exec("clear")
        # Switch to bash
        self.shell_exec("bash")
        if not self.kinit():
            self.close()
            _err("Cannot initialize kerberos token.")
        # Get qsub path
        output = self.shell_exec("which qsub")
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



####### Read params #######
confPath = "../../media/files/conformers/1-10000/40/176"
host = "zuphux.metacentrum.cz"
username = "xjur2k"
password = "LW01i20!ABC"

cpu=8
ram=32
limitHs = 10
membrane = "../../media/files/membranes/DOPC/micelle.mic"
membraneName = "DOPC"
temperature = 25
cosmoType="perm" # "mic"
queue = "elixir"

__main__(
    confPath, 
    host, 
    username, 
    password,
    cpu=cpu,
    ram=ram,
    limitHs=limitHs,
    membrane=membrane,
    membraneName=membraneName,
    temp=temperature,
    cosmo=cosmoType,  
    queue=queue,
    forceRun=False)