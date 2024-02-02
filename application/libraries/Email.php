<?php 
//Import the PHPMailer class into the global namespace
use PHPMailer\PHPMailer\PHPMailer; //important, on php files with more php stuff move it to the top
use PHPMailer\PHPMailer\SMTP; //important, on php files with more php stuff move it to the top

//SMTP needs accurate times, and the PHP time zone MUST be set
//This should be done in your php.ini, but this is how to do it if you don't have access to that
date_default_timezone_set('Europe/Prague');

require_once 'vendor/autoload.php';

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
        465,
        587,
        993
    );

    const EMAIL_REGEXP = '/(?:[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*|\"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*\")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])/';

    /**
     * Constructor
     */
    function __construct()
    {
        parent::__construct();

        $this->client = new PHPMailer(true);


        $this->server = $this->config->get(Configs::EMAIL_SMTP_SERVER);
        $this->port = intval($this->config->get(Configs::EMAIL_SMTP_PORT));
        $this->username = $this->config->get(Configs::EMAIL_SMTP_USERNAME);
        $this->password = $this->config->get(Configs::EMAIL_SMTP_PASSWORD);
        $this->email_sender = array
        (
            $this->config->get(Configs::EMAIL),
            $this->config->get(Configs::EMAIL_SERVER_USERNAME)
        );

        // Default port value
        if(!in_array($this->port, self::$valid_ports))
        {
            $this->port = self::$valid_ports[0];
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
        $this->client->Port = $this->port;

        if ($this->port == 465)
        {
            $this->client->SMTPSecure = 'ssl';
        }
        else
        {
            $this->client->SMTPSecure = 'tls';
        }

        $this->client->SMTPAuth = true;

        $this->client->CharSet = 'UTF-8';
        $this->client->isSMTP();
        $this->client->Host = $this->server;

        if($this->username && $this->password)
        {
            $this->client->Username = $this->username;
            $this->client->Password = $this->password;
        }

        $this->client->SetFrom($this->email_sender[0], $this->email_sender[1]);
        $this->client->addReplyTo($this->email_sender[0], $this->email_sender[1]);
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
            $subject = 'MolMeDB: info';
        }

        // Add recipients
        foreach($valid_recipients as $email => $name)
        {
            if(Regexp::is_valid_email($email))
                $this->client->AddAddress($email, $name);
            else
                $this->client->AddAddress($name, $name);
        }

        $this->client->Subject = $subject;
        $this->client->Body = $text;
        $this->client->AltBody = strip_tags($text);

        if($attachment_path)
        {
            $this->client->addAttachment->attach($attachment_path);
        }

        if(!$this->client->send())
        {
            $this->client->clearAllRecipients();
            throw new Exception('Email was not send.');
        }

        $this->client->clearAllRecipients();
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