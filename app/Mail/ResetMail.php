<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetMail extends Mailable
{
   use Queueable, SerializesModels;

   protected $cajero;

   public function __construct($cajero)
   {
      $this->cajero = $cajero;
   }

   public function build()
   {
      return $this->view('emails.reset')
         ->with(['cajero' => $this->cajero]);
   }
}
