from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from lib.router import Router
from lib.exceptions.MissingParameter import MissingParameterError
from lib.exceptions.InvalidRoute import InvalidPathError
from lib.exceptions.InvalidParameter import InvalidParameterError
from lib.httpRequest import HttpRequest


class SERVICE(ThreadingHTTPServer):
    # Server initialization 
    def __init__(self, port):
        ThreadingHTTPServer.__init__(self, ("", port), SERVICE_HANDLER)


# Init Router and run tests at first
router = Router()
router.registerRoutes()
if not router.runTests():
    exit('Tests failed. Exiting...')


# Handler for proccessing HTTP requests
class SERVICE_HANDLER(BaseHTTPRequestHandler):
    # Proccess GET request
    def do_GET(self):
        self.handleRequest()

    # Proccess POST request (a JSON object body, for batch endpoints)
    def do_POST(self):
        self.handleRequest()

    def handleRequest(self):
        try:
            request = HttpRequest(self)
            request.resolve()
            request.answer()
        except MissingParameterError as e:
            print({"error": str(e)})
            self.handleError(400, str(e))
        except InvalidPathError as e:
            print({"error": str(e)})
            self.handleError(404, str(e))
        except InvalidParameterError as e:
            print({"error": str(e)})
            self.handleError(422, str(e))
        except Exception as e:
            print({"error": str(e)})
            self.handleError(500, str(e))
            
    
    def handleError(self, code, err):
        self.send_response(code)
        self.send_header("Content-type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps({"error": str(err)}).encode("utf-8"))
    

# Where to listen
port = 80

RDKIT_SERVER = SERVICE(port)

RDKIT_SERVER.serve_forever()


