import re, os, time
import paramiko

CODE_INIT = 0
CODE_OK = 1
CODE_WAITING = 2
CODE_ERR = 3

CODE_EXISTS = 10
CODE_IS_RUNNING = 11
CODE_HAS_RESULT = 12

STEP_INPUT_CHECK = 0
STEP_INIT = 1

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

def _err(text, sshClient = None):
    print("Error:", text)
    if sshClient: sshClient.close()
    exit()

def _step(num, text):
    print("STEP", str(num) + ":", text)

def _log(step, code, suffix = ""):
    print("_LOG_: " + str(step) + "/" + str(code) + " [" + suffix + "]")

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

    def kinit(self):
        out = self.shell_exec("kinit")
        if not len(out):
            return True
        # Set password if required
        if "password" in out[0].lower():
            out = self.shell_exec(self.password, clear=False)
        return True

    def close(self):
        # if self.sftp: self.sftp.close()
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
        if (self.username + "@") in str(out[-1]).lower():
            out.pop()

        if len(command) > 10:
            command = command[:10]

        if len(out) and str(command).lower() in str(out[0]).lower():
            out.pop(0)
        return out
    

def __main__(server, username, password):
    import json
    sshClient = SSHClient(username=username, password=password,host=server, port=22)
    jobs = sshClient.shell_exec("qstat -xwru " + username)

    jobs = [str(row).split() for row in jobs]

    print(
        json.dumps(jobs)
    )
    sshClient.close()


# Read params
import sys, getopt
params = sys.argv[1:]

try:
    opts, args = getopt.getopt(params, "", [
        "host =",
        "username =",
        "password =",
    ])
except Exception as e:
    print("Invalid parameter set.")
    print(e)
    _log(STEP_INPUT_CHECK, CODE_ERR)
    exit(2)

username = password= host = None

try:
    for opt, arg in opts:
        opt = str(opt).strip()
        if opt == "--username":
            username = arg
        elif opt == "--password":
            password = arg
        elif opt == "--host":
            host = arg

except Exception as err:
    print("\n!! " + str(err) + " !!\n")
    print(help)
    _log(STEP_INPUT_CHECK, CODE_ERR)
    exit(2)

if None in [username, password, host]:
    _err("Invalid parameters")

__main__(host, username, password)