<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $fundacione;

    public function __construct($fundacione)
    {
        $this->fundacione = $fundacione;
    }

    public function build()
    {
        return $this->view('emails.reset')
            ->with(['fundacione' => $this->fundacione]);
    }
}
