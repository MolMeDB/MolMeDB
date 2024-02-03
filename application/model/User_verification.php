<?php

use Symfony\Polyfill\Intl\Idn\Resources\unidata\Regex;

/**
 * Remote users verification database
 * - hold info about account authorizations
 * 
 * @property int $id
 * @property int $id_user
 * @property Users $user
 * @property int $id_email
 * @property Email_queue $email
 * @property string $token
 * @property int $total_sent
 * @property int $approved
 * @property string $validity_date
 * @property string $last_sent_date
 * @property string $create_date
 * 
 * @author Jakub Juračka
 */
class User_verification extends Db 
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'user_verification';
        parent::__construct($id);
    }

    /**
     * Link to other table
     */
    protected $has_one = array
    (
        'id_user',
        'id_email' => array
        (
            'var' => 'email',
            'class' => 'Email_queue'
        ),
    );

    /**
     * Returns by token
     * 
     * @param string $token
     * 
     * @return User_verification
     */
    public static function get_by_token($token)
    {
        return self::instance()->where('token LIKE', $token)->get_one();
    }

    /**
     * Check if token already expired
     * 
     * @return bool
     */
    public function has_expired()
    {
        if(!$this->id || $this->validity_date == NULL)
        {
            return true;
        }

        return strtotime('now') >= strtotime($this->validity_date);
    }

    /**
     * Checks if user is verified
     * 
     * @return bool
     */
    public function is_verified($id_user)
    {
        $user = new Users($id_user);

        if(!$user->id)
        {
            throw new MmdbException('Invalid user id.', 'User does not exists.');
        }

        // Disabled all old tokens
        $old = $this->where('id_user', $user->id)->order_by('id', 'desc')->get_one();

        return $old && $old->id && $old->approved;
    }

    /**
     * Resent last verification email
     * 
     * @param int $id_user
     * 
     */
    public function resend_email($id_user)
    {
        $user = new Users($id_user);

        if(!$user->id)
        {
            throw new MmdbException('Invalid user id.', 'User does not exists.');
        }

        if(!Regexp::is_valid_email($user->email))
        {
            throw new MmdbException('Invalid email.', 'Email address is not valid.');
        }

        $ver = $this->where(array
            (
                'id_user' => $id_user,
                'id_email IS NOT' => null
            ))
            ->order_by('id', 'desc')
            ->get_one();

        if(!$ver->id)
        {
            // Not exists? Create new.
            $ver = $this->create_verification($user->id);

            // Change password
            $password = Encrypt::generate_password();
            $user->password = Users::return_fingerprint($password);
            $user->save();

            // Find message and fill variables
            $message = Messages::get_by_type(Messages::TYPE_VALIDATION_WELCOME_MESSAGE);
            $email_text = $message->email_text;

            $data = array
            (
                'login' => $user->login,
                'password' => $password,
                'validation_url' => Url::verification($ver->token)
            );

            foreach($data as $key => $val)
            {
                $email_text = str_replace("{{$key}}", $val, $email_text);
            }

            // Send email to the user
            $eq = new Email_queue();
            $eq->recipient_email = $user->email;
            $eq->subject = $message->email_subject;
            $eq->text = $email_text;
            $eq->status = Email_queue::SENDING;
            $eq->save();

            // Link email to verification
            $ver->id_email = $eq->id;
            $ver->save();
        }
        else
        {
            // Just re-send
            // Must be at least 5 minutes between sendings
            if($ver->last_sent_date && strtotime($ver->last_sent_date) > strtotime('-5 minutes'))
            {
                $minutes = round(abs(strtotime('+5 minutes', strtotime($ver->last_sent_date)) - strtotime('now')) / 60);

                $msg = $minutes < 2 ? 'one minute.' : $minutes . ' minutes.';

                throw new MmdbException('Too often sending ver. email.', 'Please wait for the email. The next email can be send in ' . $msg);
            }

            $ver->total_sent++;
            $ver->validity_date = date('Y-m-d H:i:s', strtotime('+7 days'));
            $ver->last_sent_date = date('Y-m-d H:i:s');
            $ver->email->status = Email_queue::SENDING;
            $ver->email->save();
            $ver->save();
        }
    }

    /**
     * Creates validation token for user
     * 
     * @param int $id_user
     * 
     * @return User_verification
     */
    public function create_verification($id_user)
    {
        $user = new Users($id_user);

        if(!$user->id)
        {
            throw new MmdbException('Invalid user id.', 'User does not exists.');
        }

        // Disabled all old tokens
        $old = $this->where('id_user', $user->id)->get_all();

        foreach($old as $r)
        {
            $r->validity_date = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $r->save();
        }

        $hash = md5(openssl_random_pseudo_bytes(20)) . md5($user->id . date('Y-m-d H:i:s')); // 64 Chars

        $token = new User_verification();
        $token->id_user = $user->id;
        $token->token = $hash;
        $token->total_sent = 0;
        $token->approved = 0;
        $token->validity_date = date('Y-m-d H:i:s', strtotime('+7 days'));
        $token->last_sent_date = NULL;
        
        $token->save();

        return $token;
    }
}