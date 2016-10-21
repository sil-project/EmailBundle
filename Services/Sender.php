<?php

namespace Librinfo\EmailBundle\Services;

use Doctrine\ORM\EntityManager;
use Librinfo\EmailBundle\Services\SwiftMailer\DecoratorPlugin\Replacements;

class Sender
{
    /**
     *
     * @var EntityManager
     */
    private $manager;
    
    private $tracker;
    
    private $inlineAttachmentsHandler;
    
    /**
     *
     * @var Swift_Mailer $directMailer
     */
    private $directMailer;
    
    /**
     *
     * @var Swift_Mailer $spoolMailer
     */
    private $spoolMailer;

    /**
     *
     * @var Email $email
     */
    private $email;

    /**
     *
     * @var Array $attachments
     */
    private $attachments;

    /**
     *
     * @var Boolean $needsSpool Wheter the email has one or more recipients
     */
    private $needsSpool;
    
    /**
     * @param EntityManager $manager
     */
    public function __construct(EntityManager $manager, $tracker, $inlineAttachmentsHandler, $directMailer, $spoolMailer)
    {
        $this->manager = $manager;
        $this->tracker = $tracker;
        $this->inlineAttachmentsHandler = $inlineAttachmentsHandler;
        $this->directMailer = $directMailer;
        $this->spoolMailer = $spoolMailer;
        dump($directMailer);
        dump($inlineAttachmentsHandler);
    }
    
    public function send($email)
    {
        $this->email = $email;
        $this->attachments = $email->getAttachments();
        $addresses = explode(';', $this->email->getFieldTo());
        
        $this->needsSpool = count($addresses) > 1;
        
        if( $this->needsSpool )
            $this->spoolSend($addresses);
        else
            $this->directSend($addresses);
    }

    /**
     * Send an email directly (ajax call)
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AccessDeniedException, NotFoundHttpException
     */
//    public function sendAjaxAction(Request $request)
//    {
//        $id = $request->get('id');
//        $this->email = $this->admin->getObject($id);
//
//        if (!$this->email) {
//            throw $this->createNotFoundException(sprintf('unable to find the email with id : %s', $id));
//        }
//
//        // TODO: set the admin class accessMapping send property, then uncomment this:
//        //$this->admin->checkAccess('send', $email);
//
//        $this->attachments = $this->email->getAttachments();
//
//        //prevent resending of an email
//        if ($this->email->getSent())
//        {
//            $this->addFlash('sonata_flash_error', "Message " . $id . " déjà envoyé");
//
//            return new JsonResponse(array(
//                'status' => 'NOK',
//                'sent' => true,
//                'error' => 'librinfo.error.email_already_sent',
//            ));
//        }
//        
//        $to = explode(';', $this->email->getFieldTo());
//        $cc = $this->email->getFieldCc();
//        $bcc = $this->email->getFieldBcc();
//        $failedRecipients = [];
//        
//        // avoid SwiftRfcComplianceException on cc and bcc
//        $cc = null == $cc ? $cc : explode(';', $this->email->getFieldCc());
//        $bcc = null == $bcc ? $bcc : explode(';', $this->email->getFieldBcc());
//
//        try {
//            $nbSent = $this->directSend($to, $cc, $bcc, $failedRecipients);
//        } catch (\Exception $exc) {
//            return new JsonResponse(array(
//                'status' => 'NOK',
//                'sent' => false,
//                'error' => $exc->getMessage(),
//            ));
//        }
//
//        return new JsonResponse(array(
//            'status' => 'OK',
//            'sent' => true,
//            'error' => '',
//            'failed_recipients' => implode(';', $failedRecipients),
//        ));
//    }

    /**
     * Sends the mail directly
     * @param array $to                The To addresses
     * @param array $cc                The Cc addresses (optional)
     * @param array $bcc               The Bcc addresses (optional) 
     * @param array $failedRecipients  An array of failures by-reference (optional)
     *
     * @return int The number of successful recipients. Can be 0 which indicates failure
     */
    protected function directSend($to, &$failedRecipients = null)
    {
        $message = $this->setupSwiftMessage($to, $this->email->getFieldCc(), $this->email->getFieldBcc());

        $replacements = new Replacements($this->manager);
        $decorator = new \Swift_Plugins_DecoratorPlugin($replacements);
        $this->directMailer->registerPlugin($decorator);

        $sent = $this->directMailer->send($message, $failedRecipients);
        $this->updateEmailEntity($message);

        return $sent;
    }

    /**
     * Spools the email
     * @param Array $addresses
     */
    protected function spoolSend($addresses)
    {
        $message = $this->setupSwiftMessage($addresses);

        $this->updateEmailEntity($message);

        $sent = $this->spoolMailer->send($message);
        
        return $sent;
    }

    /**
     * @param array $to   The To addresse
     * @return Swift_Message
     */
    protected function setupSwiftMessage($to, $cc = null, $bcc = null)
    {
        $content = $this->email->getContent();
        

        $message = \Swift_Message::newInstance();
        
        if (!$this->needsSpool)
        {
            $content = $this->inlineAttachmentsHandler->handle($content, $message);
            
            if( $this->email->getTracking())
                $content = $this->tracker->addTracking($content, $to[0], $this->email->getId());
        }
        
        
        $message->setSubject($this->email->getFieldSubject())
                ->setFrom(trim($this->email->getFieldFrom()))
                ->setTo($to)
                ->setBody($content, 'text/html')
                ->addPart($this->email->getTextContent(), 'text/plain')
        ;
        
        if( !empty($this->cc) )
            $message->setCc($cc);
        
        if( !empty($bcc) )
            $message->setBcc($bcc);

        $this->addAttachments($message);

        return $message;
    }

    /**
     * Adds attachments to the Swift_Message
     * @param Swift_Message $message
     */
    protected function addAttachments($message)
    {
        if (count($this->attachments) > 0)
        {
            foreach ($this->attachments as $file)
            {
                $attachment = \Swift_Attachment::newInstance()
                        ->setFilename($file->getName())
                        ->setContentType($file->getMimeType())
                        ->setBody($file->getFile())
                ;
                $message->attach($attachment);
            }
        }
    }

    /**
     *
     * @param Swift_Message $message
     * @param Boolean $isNewsLetter
     */
    protected function updateEmailEntity($message)
    {
        if ($this->needsSpool)
        {
            //set the id of the swift message so it can be retrieved from spool fulshQueue()
            $this->email->setMessageId($message->getId());
        } else if (!$this->email->getIsTest())
        {
            $this->email->setSent(true);
        }
        $this->manager->persist($this->email);
        $this->manager->flush();
    }

}