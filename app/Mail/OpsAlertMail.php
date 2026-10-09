<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OpsAlertMail extends Mailable
{
    public function __construct(public readonly string $alertSubject, public readonly array $context = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[CrickPro Payments] '.$this->alertSubject);
    }

    public function content(): Content
    {
        $json = json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Content(htmlString: '<h3>'.e($this->alertSubject).'</h3><pre>'.e((string) $json).'</pre>');
    }
}
