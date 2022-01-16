<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

use App\Service\MpdfService;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\Correspondence;
use App\Entity\Reservation;
use App\Entity\FileCorrespondence;
use App\Interfaces\ITemplateRenderer;
use App\Entity\MailAttachment;
use App\Entity\Invoice;
use App\Service\InvoiceService;

class TemplatesService
{
    private $em = null;
    private $app = null;
    private $requestStack;
    private $mpdfs;
    private $twig;
    private $webHost;
    private $translator;

    /**
     * @param Application $app
     */
    public function __construct(string $webHost, Environment $twig, EntityManagerInterface $em, RequestStack $requestStack, MpdfService $mpdfs, TranslatorInterface $translator)
    {
        $this->em = $em;
	$this->requestStack = $requestStack;
        $this->mpdfs = $mpdfs;
        $this->twig = $twig;
        $this->webHost = $webHost;
        $this->translator = $translator;
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
        $templateId = $request->request->get("type-" . $id);
        $type = $this->em->getRepository(TemplateType::class)->find($templateId);
        if(!($type instanceof TemplateType)) {
            //throw ""
        }
        $template->setTemplateType($type);
        $template->setName(trim($request->request->get("name-" . $id)));
        $template->setText($request->request->get("text-" . $id));
        $template->setParams($request->request->get("params-" . $id));
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
        
        $str = $this->replaceTwigSyntax($template->getText());
        $templateStr = $this->twig->createTemplate($str);
        
        return $templateStr->render($params);        
    }
    
    public function getReferencedReservationsInSession()
    {
        $reservations = Array();
        if($this->requestStack->getSession()->has("selectedReservationIds")) {
            $selectedReservationIds = $this->requestStack->getSession()->get("selectedReservationIds");
            foreach($selectedReservationIds as $id) {
                $reservations[] = $this->em->getReference(Reservation::class, $id);
            }
        }
        return $reservations;
    }
    
    public function getCorrespondencesForAttachment() {
        $selectedReservationIds = $this->requestStack->getSession()->get("selectedReservationIds");
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
    
    public function makeCorespondenceOfInvoice($id, InvoiceService $is) : ?int {
        $invoice = $this->em->find(Invoice::class, $id);
        if(!$invoice instanceof Invoice) {
            return null;
        }
        $templates = $this->em->getRepository(Template::class)->loadByTypeName(array('TEMPLATE_INVOICE_PDF'));
        $defaultTemlate = $this->getDefaultTemplate($templates);
        $templateOutput = "";
        if($defaultTemlate !== null) {
            $templateOutput = $this->renderTemplate($defaultTemlate->getId(), $id, $is);
        }
        
        $reservations = $this->getReferencedReservationsInSession();
        
        $fileId = 0;
        foreach($reservations as $reservation) {
            $file = new FileCorrespondence();
            $file->setFileName($this->translator->trans('invoice.number.short') . '-'.$invoice->getNumber())
                 ->setName($this->translator->trans('invoice.number.short') . '-'.$invoice->getNumber())
                 ->setText($templateOutput)
                 ->setTemplate($defaultTemlate)
                 ->setReservation($reservation);                    
            $this->em->persist($file);
            $this->em->flush();
            $fileId = $file->getId();
        }
        
        // return the last file id
        return $fileId;
    }
    
    public function addFileAsAttachment($cId, $reservations) {         
        $fileIds = Array();

        foreach($reservations as $reservation) {
            // save file ids in context of reservation id
            $fileIds[$reservation->getId()] =  $cId;
        }                 

        $attachments = $this->requestStack->getSession()->get("templateAttachmentIds");
        $attachments[] = $fileIds;
        $this->requestStack->getSession()->set("templateAttachmentIds", $attachments);  

        return true;
    }
    
    /**
     * Returns a MailAttachment entity which can be passed to Mailer
     * @param type $attachmentId
     * @return MailAttachment|null
     */
    public function getMailAttachment($attachmentId) : ?MailAttachment {        
        /* @var $attachment \App\Entity\Correspondence */
        $attachment = $this->em->getRepository(Correspondence::class)->find($attachmentId);
        if($attachment instanceof FileCorrespondence) {            
            $data = $this->getPDFOutput($attachment->getText(), $attachment->getName(), $attachment->getTemplate(), true);
            $mailAttachment = new MailAttachment($data, $attachment->getName() . '.pdf', 'application/pdf');
            return $mailAttachment;
        }
        
        return null;
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
        
        $inputMapped = $this->mapImageSrc($input);
        
        /*
         * mode
         * 0 - Use this (default) if the text you pass is a complete HTML page including head and body and style definitions.
         * 1 - Use this when you want to set a CSS stylesheet 
         * 2 - Write HTML code without the <head> information. Does not need to be contained in <body>
         */
        $mpdf->WriteHTML($inputMapped, 0);

        return $mpdf->Output($name . '.pdf', $dest);
    }
    
    /**
     * This maps the src of images to the real web host.
     * This is sometimes needed e.g. when using it with docker. 
     * The docker web host is "web" when the application is requested via "localhost" in the browser, 
     * mpdf uses the host from the request, which is localhost. But there is no web server listening on localhost in the php container. 
     * That's why we need to change the src to the real host "web".
     * @param string $input
     * @return string
     */
    private function mapImageSrc($input) {
        $host = rtrim($this->webHost, '/').'/';
        return preg_replace('/src="\/(.*)"/i', 'src="'.$host.'$1"', $input);
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
    
    private function replaceTwigSyntax(string $string) : string {
        $t1 = str_replace("[[", "{{", $string);
        $t2 = str_replace("]]", "}}", $t1);
        
        $t3 = str_replace("[%", "{%", $t2);
        $t4 = str_replace("%]", "%}", $t3);
        
        $t5 = str_replace("[#", "{#", $t4);
        $t6 = str_replace("#]", "#}", $t5);
        
        $t7 = preg_replace("/<div class=\"footer\">(.*)<\/div>/s", "<htmlpagefooter name=\"footer\">$1</htmlpagefooter><sethtmlpagefooter name=\"footer\" value=\"on\"></sethtmlpagefooter>", $t6);
        
        return $t7;
    }
}
