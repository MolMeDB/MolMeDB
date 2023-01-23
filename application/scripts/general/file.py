
class File:
    def __init__(self):
        pass

    def getFolder(path):
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
