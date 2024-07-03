<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CodigoConfirmationMail extends Mailable
{

   use Queueable, SerializesModels;

   public $codigoConfirmacion;

   public function __construct($codigoConfirmacion)
   {
      $this->codigoConfirmacion = $codigoConfirmacion;
   }

   public function build()
   {
      return $this->view('emails.codigo_confirmacion')
         ->with([
            'codigoConfirmacion' => $this->codigoConfirmacion,
         ]);
   }
}
