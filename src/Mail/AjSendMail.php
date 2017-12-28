<?php

namespace Ajency\Ajfileimport\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Log;
use View;

class AjSendMail extends Mailable
{
    use Queueable, SerializesModels;
    private $params;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($params = array())
    {
        $this->params = $params;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        //return $this->view('view.name');
        Log:info("Ajfileimport\Mail :------------------------");
        Log::info($this->params);

        $from = isset($this->params['from']) ? $this->params['from'] : 'Aj laravel import log';
        $subject = isset($this->params['subject']) ? $this->params['subject'] : 'Aj laravel import log';


        $to          = isset($this->params['to']) ? $this->params['to'] : array();
        $cc          = isset($this->params['cc']) ? $this->params['cc'] : array();
        $bcc         = isset($this->params['bcc']) ? $this->params['bcc'] : array();
        $template    = /*isset($this->params['template']) ? $this->params['template'] : */'AjcsvimportView::importlogs';
        $mail_params = isset($this->params['mail_params']) ? $this->params['mail_params'] : array();
        $attachments = isset($this->params['attachment']) ? $this->params['attachment'] : array();

        $this->from($from);
        $this->to($to);
        $this->cc($cc);
        $this->bcc($bcc);
        $this->view($template)->with($mail_params);
        $this->subject($subject);

        if (isset($attachments) ){

            if(is_array($attachments)){
                foreach ($attachments as $value) {
                    $this->attach($value);
                }
            }

            //return $this->view('AjcsvimportView::importlogs')->attach($this->params['attachment'])->subject('Aj laravel import log')->from('parag@ajency.in');
        } /*else {
        return $this->view('AjcsvimportView::importlogs')->subject('Aj laravel import log')->from('parag@ajency.in');
        }*/
        return $this;

    }
}
