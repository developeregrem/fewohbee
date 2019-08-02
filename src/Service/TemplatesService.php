<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Templating\PhpEngine;
use Symfony\Component\Templating\TemplateNameParser;

use App\Service\MpdfService;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\Correspondence;
use App\Entity\Reservation;
use App\Entity\FileCorrespondence;
use App\Interfaces\ITemplateRenderer;

class TemplatesService
{

    private $em = null;
    private $app = null;
    private $session;
    private $mpdfs;
    private $twig;

    /**
     * @param Application $app
     */
    public function __construct(\Twig_Environment $twig, EntityManagerInterface $em, SessionInterface $session, MpdfService $mpdfs)
    {
        $this->em = $em;
	$this->session = $session;
        $this->mpdfs = $mpdfs;
        $this->twig = $twig;
    }

    /**
     * Extract form data and return Template object
     * @param Request $request
     * @param string $id
     * @return Template
     */
    public function getEntityFromForm(Request $request, $id = 'new')
    {

        $template = new Template();
        if ($id !== 'new') {
            $template = $this->em->getRepository(Template::class)->find($id);
        }
        $templateId = $request->get("type-" . $id);
        $type = $this->em->getRepository(TemplateType::class)->find($templateId);
        if(!($type instanceof TemplateType)) {
            //throw ""
        }
        $template->setTemplateType($type);
        $template->setName(trim($request->get("name-" . $id)));
        $template->setText($request->get("text-" . $id));
        $template->setParams($request->get("params-" . $id));
        if($request->request->has("default-" . $id)) {
            $template->setIsDefault(true);
        } else {
            $template->setIsDefault(false);
        }

        return $template;
    }

    /**
     * Delete entity
     * @param int $id
     * @return bool
     */
    public function deleteEntity($id)
    {
        $template = $this->em->getRepository(Template::class)->find($id);

        $this->em->remove($template);
        $this->em->flush();

        return true;
    }
    
    public function renderTemplateForReservations($templateId, $reservations)
    {        
        /* @var $template \App\Entity\Template */
        $template = $this->em->getRepository(Template::class)->find($templateId);
        
        $templateStr = $this->twig->createTemplate($template->getText());
        
        return $templateStr->render(array(
            "reservations" => $reservations
        ));        
    }
    
    public function renderTemplate(int $templateId, $param, ITemplateRenderer $serviceObj)
    {        
        /* @var $template Template */
        $template = $this->em->getRepository(Template::class)->find($templateId);
        
        $params = Array();
        $service = $template->getTemplateType()->getService();
        if(!empty($service)) {
            // each service must implement the ITemplateRenderer interface
            $params = $serviceObj->getRenderParams($template, $param);
        }        

        $templateStr = $this->twig->createTemplate($template->getText());
        
        return $templateStr->render($params);        
    }
    
    public function getReferencedReservationsInSession()
    {
        $reservations = Array();
        if($this->session->has("selectedReservationIds")) {
            $selectedReservationIds = $this->session->get("selectedReservationIds");
            foreach($selectedReservationIds as $id) {
                $reservations[] = $this->em->getReference(Reservation::class, $id);
            }
        }
        return $reservations;
    }
    
    public function getCorrespondencesForAttachment() {
        $selectedReservationIds = $this->session->get("selectedReservationIds");
        $correspondences = Array();
        foreach ($selectedReservationIds as $reservationId) {
            $reservation = $this->em->getReference(Reservation::class, $reservationId);
            $cs = $reservation->getCorrespondences();
            if(count($cs) > 0) {
                $correspondences = array_merge($correspondences, $cs->toArray());
            }
        }
        return $correspondences;
    }
    
    public function addFileAsAttachment($cId, $reservations) {         
        $fileIds = Array();

        foreach($reservations as $reservation) {
            // save file ids in context of reservation id
            $fileIds[$reservation->getId()] =  $cId;
        }                 

        $attachments = $this->session->get("templateAttachmentIds");
        $attachments[] = $fileIds;
        $this->session->set("templateAttachmentIds", $attachments);  

        return true;
    }
    
    public function attachToMail($attachmentId, &$mail) 
    {
        /* @var $attachment \App\Entity\Correspondence */
        $attachment = $this->em->getRepository(Correspondence::class)->find($attachmentId);
        if($attachment instanceof FileCorrespondence) {
            $data = $this->getPDFOutput($attachment->getText(), $attachment->getName(), $attachment->getTemplate(), true);
            $file = (new \Swift_Attachment())
            ->setFilename($attachment->getName() . '.pdf')
            ->setContentType('application/pdf')
            ->setBody($data)
            ;
            $mail->attach($file);
        }
        
        return $mail;
    }
    
    public function getPDFOutput($input, $name, $template, $noResponseOutput = false)
    {
        /*
         * I: send the file inline to the browser. The plug-in is used if available. The name given by filename is used when one selects the "Save as" option on the link generating the PDF.
         * D: send to the browser and force a file download with the name given by filename.
         * F: save to a local file with the name given by filename (may include a path).
         * S: return the document as a string. filename is ignored.
         */
        $dest = ($noResponseOutput ? "S" : "D");
        $mpdf = $this->mpdfs->getMpdf();

        $params = json_decode($template->getParams());
        $mpdf->addPage(
            $params->orientation, '', '', '', '',
            $params->marginLeft,
            $params->marginRight,
            $params->marginTop,
            $params->marginBottom,
            $params->marginHeader,
            $params->marginFooter);

        /*
         * mode
         * 0 - Use this (default) if the text you pass is a complete HTML page including head and body and style definitions.
         * 1 - Use this when you want to set a CSS stylesheet 
         * 2 - Write HTML code without the <head> information. Does not need to be contained in <body>
         */
        $mpdf->WriteHTML($input, 0);

        return $mpdf->Output($name . '.pdf', $dest);
    }
    
    /**
     * Returns the default Template or null
     * @return null
     */
    public function getDefaultTemplate($templates) {
        // find default template
        foreach ($templates as $template) {
            if ($template->getIsDefault()) {
                return $template;
            }
        }

        return null;
    }
}
