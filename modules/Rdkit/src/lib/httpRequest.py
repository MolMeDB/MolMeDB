from http.server import BaseHTTPRequestHandler
import base64
import json
from typing import Any, Optional, Dict
from urllib.parse import urlparse, unquote
from lib.router import Router, ApiResponse, ResponseType
from lib.exceptions.InvalidParameter import InvalidParameterError

class HttpRequest():
    def __init__(self, request: BaseHTTPRequestHandler) -> None:
        self.request = request
        self.authToken: Optional[str] = self._parse_auth()
        self.query: Dict[str, Any] = {**self._parse_query(), **self._parse_body()}
        self.uri: str = self._parse_uri()
        self.response: Optional[ApiResponse] = None
        # Define router and register routes
        self.router = Router()
        self.router.registerRoutes()
        
    def _parse_auth(self):
        auth = self.request.headers.get('Authorization')
        if auth and str(auth).strip().startswith("Basic"):
            auth = str(auth).replace("Basic", "").strip()
            return base64.b64decode(auth).decode('utf-8')
            
    def _parse_query(self) -> Dict[str, str]:
        query = urlparse(self.request.path).query
        if query and "=" in query:
            params = dict(qc.split("=", 1) for qc in query.split("&"))
        else:
            params = {}
        return {k: unquote(v) for k, v in params.items()}
        
    # A POST request carries its parameters as a JSON object.
    MAX_BODY_BYTES = 10 * 1024 * 1024

    def _parse_body(self) -> Dict[str, Any]:
        if self.request.command != "POST":
            return {}

        try:
            length = int(self.request.headers.get('Content-Length') or 0)
        except ValueError:
            raise InvalidParameterError("body")

        if length <= 0:
            return {}

        if length > self.MAX_BODY_BYTES:
            raise InvalidParameterError("body")

        try:
            body = json.loads(self.request.rfile.read(length).decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise InvalidParameterError("body")

        if not isinstance(body, dict):
            raise InvalidParameterError("body")

        return body

    def _parse_uri(self) -> str:
        path = self.request.path
        path = path.strip("?") + "?"
        return self.request.path[: path.find("?")].strip("/")
    
    
    def resolve(self):
        self.response = self.router.resolve(self.uri, self.query)
        return self.response
    
    def answer(self):
        if self.response is not None:
            if self.response.type == ResponseType.TEXT or self.response.type == ResponseType.JSON:
                self.request.send_response(self.response.code)
                self.request.send_header("Content-type", "application/json")
                self.request.end_headers()
                
                # For json data responses
                import json
                if isinstance(self.response.data, dict):
                    body = json.dumps(dict({
                        "data": self.response.data}
                    )).encode("utf-8")
                elif isinstance(self.response.data, str):
                    body = json.dumps(dict({
                        "data": self.response.data
                    })).encode("utf-8")
                else:
                    body = self.response.data
                self.request.wfile.write(body)
            elif self.response.type == ResponseType.FILECONTENT:
                data_bytes = self.response.data.encode("utf-8")  # nebo jiná vhodná encoding
                self.request.send_response(self.response.code)
                self.request.send_header("Content-type", "application/octet-stream")
                self.request.send_header("Content-Length", str(len(data_bytes)))
                self.request.end_headers()
                self.request.wfile.write(data_bytes)