<?php require_once 'vendor/autoload.php';

class Email extends Controller
{
    /** Server info */
    protected $server;
    protected $port;
    protected $username;
    protected $password;
    protected $email_sender;

    /** Holds email client instance */
    protected $client;

    public static $valid_ports = array
    (
        25,
        465,
        587
    );

    const EMAIL_REGEXP = '/(?:[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*|\"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*\")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])/';

    /**
     * Constructor
     */
    function __construct()
    {
        parent::__construct();

        $this->server = $this->config->get(Configs::EMAIL_SMTP_SERVER);
        $this->port = intval($this->config->get(Configs::EMAIL_SMTP_PORT));
        $this->username = $this->config->get(Configs::EMAIL_SMTP_USERNAME);
        $this->password = $this->config->get(Configs::EMAIL_SMTP_PASSWORD);
        $this->email_sender = (object) array
        (
            $this->config->get(Configs::EMAIL) => $this->config->get(Configs::EMAIL_SERVER_USERNAME)
        );

        // Default port value
        if(!in_array($this->port, self::$valid_ports))
        {
            $this->port = 25;
        }

        if(!$this->server)
        {
            throw new Exception('Invalid SMTP settings.');
        }

        if(!$this->config->get(Configs::EMAIL) || !$this->config->get(Configs::EMAIL_SERVER_USERNAME))
        {
            throw new Exception('Invalid email or name.');
        }

        $this->init();
    }

    /**
     * Initialize client
     */
    private function init()
    {
        if($this->port == 25)
        {
            $transport = new Swift_SmtpTransport($this->server, $this->port);
        }
        else if ($this->port == 465)
        {
            $transport = new Swift_SmtpTransport($this->server, $this->port, 'ssl');
        }
        else if ($this->port == 587)
        {
            $transport = new Swift_SmtpTransport($this->server, $this->port, 'tls');
        }

        if($this->username && $this->password)
        {
            $transport->setUsername($this->username);
            $transport->setPassword($this->password);
        }

        $this->client = new Swift_Mailer($transport);
    }

    /**
     * Sends new email message
     * 
     * @param array $recipients IN FORM ['email_address_1', 'username' => 'email_address_2']
     * @param string $subject
     * @param string $text
     */
    public function send($recipients, $subject, $text, $attachment_path = NULL)
    {
        // Dont send empty emails
        if(!$text || trim($text) === '')
        {
            throw new Exception('Email text is empty.');
        }

        // Check recipients validity
        if(!is_array($recipients))
        {
            $recipients = [$recipients];
        }

        $valid_recipients = [];

        foreach($recipients as $name => $email)
        {
            if(is_numeric($name) || trim($name) == '')
            {
                $name = NULL;
            }

            if(!self::check_email_validity($email))
            {
                throw new Exception('Invalid email address: ' . $email);
            }

            if($name !== NULL)
            {
                $valid_recipients[$email] = $name;
            }
            else
            {
                $valid_recipients[] = $email;
            }
        }

        if(!$subject || trim($subject) == '')
        {
            $subject = 'MolMeDB info';
        }

        // Create a message
        $message = new Swift_Message($subject);
        $message->setContentType("text/html");

        $message->setFrom($this->email_sender)
            ->setTo($valid_recipients)
            ->setBody($text);

        if($attachment_path)
        {
            $message->attach(Swift_Attachment::fromPath($attachment_path));
        }

        // Send the message
        $result = $this->client->send($message);

        if(!$result)
        {
            throw new Exception('Email was not send.');
        }
    }

    /**
     * Checks email address validity
     * 
     * @param string $email
     * 
     * @return bool
     */
    public static function check_email_validity($email)
    {
        preg_match(self::EMAIL_REGEXP, $email, $match);

        if(!count($match) || $match[0] !== $email)
        {
            return false;
        }

        return true;
    }
}