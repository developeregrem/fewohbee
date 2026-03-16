<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\Correspondence;
use App\Entity\FileCorrespondence;
use App\Entity\Invoice;
use App\Entity\MailAttachment;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Service\TemplatePreview\TemplateRenderParamsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Error\Error as TwigError;
use Twig\Environment;

class TemplatesService
{
    public const EXAMPLES_BASE_URL = 'https://raw.githubusercontent.com/developeregrem/fewohbee-examples/master/templates/';
    /**
     * Default mPDF layout parameters for templates.
     */
    public const DEFAULT_TEMPLATE_PARAMS = [
        'orientation' => 'P',
        'marginLeft' => 25.0,
        'marginRight' => 20.0,
        'marginTop' => 20.0,
        'marginBottom' => 20.0,
        'marginHeader' => 9.0,
        'marginFooter' => 9.0,
    ];
    private $webHost;

    public function __construct(
        string $webHost,
        private Environment $twig,
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private MpdfService $mpdfs,
        private TranslatorInterface $translator,
        private TemplateRenderParamsResolver $renderParamsResolver
    ) {
        $this->webHost = $webHost;
    }

    /**
     * Extract form data and return Template object.
     *
     * @param string $id
     *
     * @return Template
     */
    public function getEntityFromForm(Request $request, $id = 'new')
    {
        $template = new Template();
        if ('new' !== $id) {
            $template = $this->em->getRepository(Template::class)->find($id);
        }
        $templateId = $request->request->get('type-'.$id);
        $type = $this->em->getRepository(TemplateType::class)->find($templateId);
        if (!($type instanceof TemplateType)) {
            // throw ""
        }
        $template->setTemplateType($type);
        $template->setName(trim($request->request->get('name-'.$id)));
        $template->setText($request->request->get('text-'.$id));
        $template->setParams($this->buildTemplateParamsFromRequest($request, (string) $id));
        if ($request->request->has('default-'.$id)) {
            $template->setIsDefault(true);
        } else {
            $template->setIsDefault(false);
        }

        return $template;
    }

    /**
     * Delete entity.
     *
     * @param int $id
     *
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

        return $templateStr->render([
            'reservations' => $reservations,
        ]);
    }

    public function renderTemplate(int $templateId, mixed $param): string
    {
        /* @var $template Template */
        $template = $this->em->getRepository(Template::class)->find($templateId);
        if (!($template instanceof Template)) {
            throw new \InvalidArgumentException($this->translator->trans('templates.notfound'));
        }

        $params = $this->renderParamsResolver->resolve($template, $param);

        $str = $this->replaceTwigSyntax($template->getText());
        $templateStr = $this->twig->createTemplate($str);

        return $templateStr->render($params);
    }

    /**
     * Render raw template content using the pseudo-twig syntax replacements.
     *
     * @param array<string, mixed> $params
     */
    public function renderTemplateString(string $templateText, array $params): string
    {
        try {
            $str = $this->replaceTwigSyntax($templateText);
            $templateStr = $this->twig->createTemplate($str);

            return $templateStr->render($params);
        } catch (TwigError|\Throwable $e) {
            throw new \RuntimeException($this->buildFriendlyTemplateErrorMessage($e), 0, $e);
        }
    }

    /**
     * Build a short, user-friendly template error message without stack traces.
     */
    private function buildFriendlyTemplateErrorMessage(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        if (preg_match('/Neither the property "([^"]+)".*?class "([^"]+)"/i', $message, $matches)) {
            $property = $matches[1];
            $class = $matches[2];

            return $this->translator->trans('templates.preview.render.error.property', [
                '%property%' => $property,
                '%class%' => $class,
            ]);
        }

        if (preg_match('/Variable "([^"]+)" does not exist/i', $message, $matches)) {
            return $this->translator->trans('templates.preview.render.error.variable', [
                '%variable%' => $matches[1],
            ]);
        }

        if (preg_match('/Unknown "([^"]+)" filter/i', $message, $matches)) {
            return $this->translator->trans('templates.preview.render.error.filter', [
                '%filter%' => $matches[1],
            ]);
        }

        if (preg_match('/Unexpected token|SyntaxError|Unable to parse/i', $message)) {
            return $this->translator->trans('templates.preview.render.error.syntax');
        }

        $generic = $this->translator->trans('templates.preview.render.error.generic');
        if ('' !== $message) {
            return $generic.' Details: '.$message;
        }

        return $generic;
    }

    /**
     * Default definitions for operations report templates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOperationsTemplateDefinitions(): array
    {
        return [
            [
                'file' => 'report_housekeeping_day.html.twig',
                'name' => 'templates.operations.housekeeping_day',
                'isDefault' => true,
                'params' => ['orientation' => 'L'],
            ],
            [
                'file' => 'report_housekeeping_week.html.twig',
                'name' => 'templates.operations.housekeeping_week',
                'params' => ['orientation' => 'L'],
            ],
            ['file' => 'report_housekeeping_summary.html.twig'],
            [
                'file' => 'report_frontdesk_checklist.html.twig',
                'name' => 'templates.operations.frontdesk_checklist',
            ],
            [
                'file' => 'report_meals_checklist.html.twig',
                'name' => 'templates.operations.meals_checklist',
            ],
            [
                'file' => 'report_management_monthly_summary.html.twig',
                'name' => 'templates.operations.management_monthly_summary',
            ],
        ];
    }

    /**
     * Default definitions for registration templates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegistrationTemplateDefinitions(): array
    {
        return [
            [
                'file' => 'report_registration_form.html.twig',
                'isDefault' => true,
            ],
        ];
    }

    /**
     * Import templates from a remote base URL.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public function importTemplates(TemplateType $type, array $entries, string $baseUrl): int
    {
        $client = \Symfony\Component\HttpClient\HttpClient::create();
        $imported = 0;

        foreach ($entries as $entry) {
            $templateFile = $entry['file'];
            $response = $client->request('GET', $baseUrl.$templateFile);
            if (200 !== $response->getStatusCode()) {
                continue;
            }

            $content = $response->getContent();
            $templateName = $this->resolveTemplateName(
                $type->getName(),
                $entry['name'] ?? null
            );
            $template = $this->em->getRepository(Template::class)->findOneBy([
                'templateType' => $type,
                'name' => $templateName,
            ]);
            if ($template instanceof Template) {
                continue;
            }

            $template = new Template();
            $template->setParams($this->buildTemplateParams($entry['params'] ?? []));
            $template->setIsDefault(isset($entry['isDefault']) ? (bool) $entry['isDefault'] : false);
            $template->setName($templateName);
            $template->setTemplateType($type);
            $template->setText($content);

            $this->em->persist($template);
            ++$imported;
        }

        return $imported;
    }

    /**
     * Build template params by merging custom settings with defaults.
     */
    public function buildTemplateParams(array $custom): string
    {
        $params = array_merge(self::DEFAULT_TEMPLATE_PARAMS, $custom);

        return json_encode($params, JSON_THROW_ON_ERROR);
    }

    /**
     * Parse template params from persisted JSON and merge with defaults.
     *
     * @return array<string, float|string>
     */
    public function parseTemplateParams(?string $rawParams): array
    {
        $params = self::DEFAULT_TEMPLATE_PARAMS;
        if (!is_string($rawParams) || '' === trim($rawParams)) {
            return $params;
        }

        $decoded = json_decode($rawParams, true);
        if (!is_array($decoded)) {
            return $params;
        }

        $orientation = strtoupper((string) ($decoded['orientation'] ?? $params['orientation']));
        $params['orientation'] = 'L' === $orientation ? 'L' : 'P';

        foreach (['marginLeft', 'marginRight', 'marginTop', 'marginBottom', 'marginHeader', 'marginFooter'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            if (is_numeric($decoded[$key])) {
                $params[$key] = (float) $decoded[$key];
            }
        }

        return $params;
    }

    /**
     * Build template params JSON from request fields (new structured inputs + legacy JSON fallback).
     */
    public function buildTemplateParamsFromRequest(Request $request, string $id): string
    {
        $orientationField = 'params-orientation-'.$id;
        $hasStructuredInput = $request->request->has($orientationField);
        if ($hasStructuredInput) {
            $structured = [
                'orientation' => strtoupper((string) $request->request->get($orientationField, 'P')),
            ];
            foreach (['marginLeft', 'marginRight', 'marginTop', 'marginBottom', 'marginHeader', 'marginFooter'] as $key) {
                $value = $request->request->get('params-'.$key.'-'.$id);
                if (is_numeric($value)) {
                    $structured[$key] = (float) $value;
                }
            }

            return $this->buildTemplateParams($structured);
        }

        $legacyRaw = $request->request->get('params-'.$id);
        if (is_string($legacyRaw) && '' !== trim($legacyRaw)) {
            return $this->buildTemplateParams($this->parseTemplateParams($legacyRaw));
        }

        return $this->buildTemplateParams([]);
    }

    /**
     * Resolve display name for a template.
     */
    public function resolveTemplateName(string $typeName, ?string $translationKey): string
    {
        if (null !== $translationKey) {
            return $this->translator->trans($translationKey);
        }

        return $this->translator->trans($typeName);
    }

    public function getReferencedReservationsInSession()
    {
        $reservations = [];
        if ($this->requestStack->getSession()->has(ReservationService::SESSION_SELECTED_RESERVATIONS)) {
            $selectedReservationIds = $this->requestStack->getSession()->get(ReservationService::SESSION_SELECTED_RESERVATIONS);
            foreach ($selectedReservationIds as $id) {
                $reservations[] = $this->em->getReference(Reservation::class, $id);
            }
        }

        return $reservations;
    }

    public function getCorrespondencesForAttachment()
    {
        $selectedReservationIds = $this->requestStack->getSession()->get(ReservationService::SESSION_SELECTED_RESERVATIONS);
        $correspondences = [];
        foreach ($selectedReservationIds as $reservationId) {
            $reservation = $this->em->getReference(Reservation::class, $reservationId);
            $cs = $reservation->getCorrespondences();
            if (count($cs) > 0) {
                $correspondences = array_merge($correspondences, $cs->toArray());
            }
        }

        return $correspondences;
    }

    public function makeCorespondenceOfInvoice($id, InvoiceService $is, ?string $binaryPayload = null, bool $isEInvoice = false): ?int
    {
        $invoice = $this->em->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            return null;
        }
        $templates = $this->em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF']);
        $defaultTemlate = $this->getDefaultTemplate($templates);
        $templateOutput = '';
        if (null !== $defaultTemlate) {
            $templateOutput = $this->renderTemplate($defaultTemlate->getId(), $id);
        }

        $reservations = $this->getReferencedReservationsInSession();

        $fileId = 0;
        foreach ($reservations as $reservation) {
            $file = new FileCorrespondence();
            $fileName = $is->buildInvoiceExportFilename($invoice, $isEInvoice);
            $file->setFileName($fileName)
                 ->setName($fileName)
                 ->setText($templateOutput)
                 ->setTemplate($defaultTemlate)
                 ->setReservation($reservation);
            if (null !== $binaryPayload) {
                $file->setBinaryPayload($binaryPayload);
            }
            $this->em->persist($file);
            $this->em->flush();
            $fileId = $file->getId();
        }

        // return the last file id
        return $fileId;
    }

    public function addFileAsAttachment($cId, $reservations)
    {
        $fileIds = [];

        foreach ($reservations as $reservation) {
            // save file ids in context of reservation id
            $fileIds[$reservation->getId()] = $cId;
        }

        $attachments = $this->requestStack->getSession()->get('templateAttachmentIds');
        $attachments[] = $fileIds;
        $this->requestStack->getSession()->set('templateAttachmentIds', $attachments);

        return true;
    }

    /**
     * Returns a MailAttachment entity which can be passed to Mailer.
     *
     * @param type $attachmentId
     */
    public function getMailAttachment($attachmentId): ?MailAttachment
    {
        /* @var $attachment \App\Entity\Correspondence */
        $attachment = $this->em->getRepository(Correspondence::class)->find($attachmentId);
        if ($attachment instanceof FileCorrespondence) {
            $binaryPayload = $attachment->getBinaryPayload();
            $data = $binaryPayload ?: $this->getPDFOutput($attachment->getText(), $attachment->getName(), $attachment->getTemplate(), true);

            return new MailAttachment($data, $attachment->getName().'.pdf', 'application/pdf');
        }

        return null;
    }

    public function getPDFOutput($input, $name, $template, $noResponseOutput = false, ?string $destOverride = null)
    {
        /*
         * I: send the file inline to the browser. The plug-in is used if available. The name given by filename is used when one selects the "Save as" option on the link generating the PDF.
         * D: send to the browser and force a file download with the name given by filename.
         * F: save to a local file with the name given by filename (may include a path).
         * S: return the document as a string. filename is ignored.
         */
        $dest = $destOverride ?: ($noResponseOutput ? 'S' : 'D');
        $mpdf = $this->mpdfs->getMpdf();

        $params = json_decode($template->getParams());
        $mpdf->addPage(
            $params->orientation,
            '',
            '',
            '',
            '',
            $params->marginLeft,
            $params->marginRight,
            $params->marginTop,
            $params->marginBottom,
            $params->marginHeader,
            $params->marginFooter
        );

        $inputMapped = $this->mapImageSrc($input);

        /*
         * mode
         * 0 - Use this (default) if the text you pass is a complete HTML page including head and body and style definitions.
         * 1 - Use this when you want to set a CSS stylesheet
         * 2 - Write HTML code without the <head> information. Does not need to be contained in <body>
         */
        $mpdf->WriteHTML($inputMapped, 0);

        return $mpdf->Output($name.'.pdf', $dest);
    }

    /**
     * This maps the src of images to the real web host.
     * This is sometimes needed e.g. when using it with docker.
     * The docker web host is "web" when the application is requested via "localhost" in the browser,
     * mpdf uses the host from the request, which is localhost. But there is no web server listening on localhost in the php container.
     * That's why we need to change the src to the real host "web".
     *
     * @param string $input
     *
     * @return string
     */
    private function mapImageSrc($input)
    {
        $host = rtrim($this->webHost, '/').'/';

        return preg_replace('/src="\/(.*)"/i', 'src="'.$host.'$1"', $input);
    }

    /**
     * Returns the default Template or null.
     *
     * @param Template[] $templates
     */
    public function getDefaultTemplate(array $templates): ?Template
    {
        // find default template
        foreach ($templates as $template) {
            if ($template->getIsDefault()) {
                return $template;
            }
        }
        // if no default template is set, return the first one
        if (count($templates) > 0) {
            return $templates[0];
        }

        return null;
    }

    public function getTemplateId(ManagerRegistry $doctrine, RequestStack $requestStack, string $typeName, string $sessionName): int
    {
        $templates = $doctrine->getManager()->getRepository(Template::class)->loadByTypeName([$typeName]);
        $defaultTemplate = $this->getDefaultTemplate($templates);
        $templateId = 0;
        if (null != $defaultTemplate) {
            $templateId = $defaultTemplate->getId();
        }

        return $requestStack->getSession()->get($sessionName, $templateId);
    }

    private function replaceTwigSyntax(string $string): string
    {
        // Safety net: decode persisted visual style tokens, if any exist.
        $tStyle = $this->decodeTemplateStyleTokens($string);

        // First, convert editor-friendly loop/if data attributes to real Twig tags.
        $t0 = $this->replaceDataControlSyntax($tStyle);

        $t1 = str_replace('[[', '{{', $t0);
        $t2 = str_replace(']]', '}}', $t1);

        $t3 = str_replace('[%', '{%', $t2);
        $t4 = str_replace('%]', '%}', $t3);

        $t5 = str_replace('[#', '{#', $t4);
        $t6 = str_replace('#]', '#}', $t5);

        $t7 = preg_replace('/<div class="footer">([\s\S]*?)<\/div>/i', '<htmlpagefooter name="footer">$1</htmlpagefooter><sethtmlpagefooter name="footer" value="on" page="ALL"></sethtmlpagefooter>', $t6);
        $t8 = preg_replace('/<div class="header">([\s\S]*?)<\/div>/i', '<htmlpageheader name="header">$1</htmlpageheader><sethtmlpageheader name="header" value="on" show-this-page="1"></sethtmlpageheader>', $t7);

        return $t8;
    }

    /**
     * Decode visual editor style tokens back to raw <style> blocks.
     */
    private function decodeTemplateStyleTokens(string $html): string
    {
        return preg_replace_callback(
            '/<([a-zA-Z][\w:-]*)[^>]*\bdata-template-style=(["\'])(.*?)\2[^>]*>[\s\S]*?<\/\1>/i',
            static function (array $matches): string {
                $decoded = base64_decode($matches[3], true);
                if (false === $decoded || '' === trim($decoded)) {
                    return $matches[0];
                }

                return $decoded;
            },
            $html
        ) ?? $html;
    }

    /**
     * Converts data-attribute based control syntax to Twig blocks.
     *
     * Supported:
     * - data-repeat="collection" + data-repeat-as="item"
     * - optional: data-repeat-key="keyVar" for key/value loops
     * - data-if="condition"
     *
     * Example:
     * <tr data-repeat="reservations" data-repeat-as="reservation">...</tr>
     * =>
     * {% for reservation in reservations %}<tr>...</tr>{% endfor %}
     */
    private function replaceDataControlSyntax(string $string): string
    {
        $result = $string;
        $result = $this->replaceDataLoopBlocks($result);
        $result = $this->replaceDataIfBlocks($result);

        return $result;
    }

    /**
     * Converts elements with data-repeat attributes to Twig for-blocks.
     */
    private function replaceDataLoopBlocks(string $string): string
    {
        return $this->replaceControlBlocksByAttribute($string, 'data-repeat', function (string $tag, string $attributes, string $content): string {
            $collection = $this->extractAttributeValue($attributes, 'data-repeat');
            $item = $this->extractAttributeValue($attributes, 'data-repeat-as');
            $key = $this->extractAttributeValue($attributes, 'data-repeat-key');
            if (null === $collection || null === $item) {
                return '<'.$tag.$attributes.'>'.$content.'</'.$tag.'>';
            }

            $cleanAttributes = $this->stripControlAttributes($attributes, false);
            $openTag = '<'.$tag.($cleanAttributes !== '' ? ' '.$cleanAttributes : '').'>';
            $element = $openTag.$content.'</'.$tag.'>';

            $loopExpression = null !== $key
                ? $key.', '.$item.' in '.$collection
                : $item.' in '.$collection;

            return '{% for '.$loopExpression.' %}'."\n".$element."\n".'{% endfor %}';
        });
    }

    /**
     * Converts elements with data-if attributes to Twig if-blocks.
     */
    private function replaceDataIfBlocks(string $string): string
    {
        return $this->replaceControlBlocksByAttribute($string, 'data-if', function (string $tag, string $attributes, string $content): string {
            $condition = $this->extractAttributeValue($attributes, 'data-if');
            if (null === $condition) {
                return '<'.$tag.$attributes.'>'.$content.'</'.$tag.'>';
            }

            $cleanAttributes = $this->stripControlAttributes($attributes);
            $openTag = '<'.$tag.($cleanAttributes !== '' ? ' '.$cleanAttributes : '').'>';
            $element = $openTag.$content.'</'.$tag.'>';

            return '{% if '.$condition.' %}'."\n".$element."\n".'{% endif %}';
        });
    }

    /**
     * Replaces blocks containing the given attribute while keeping balanced tag structure.
     *
     * This avoids broken replacements for nested elements of the same tag
     * (e.g. <span data-repeat>...<span>...</span>...</span>).
     *
     * @param callable(string, string, string): string $builder
     */
    private function replaceControlBlocksByAttribute(string $html, string $attributeName, callable $builder): string
    {
        $openPattern = '/<([a-zA-Z][\w:-]*)([^>]*\s'.preg_quote($attributeName, '/').'=(["\']).*?\3[^>]*)>/i';
        $offset = 0;
        $result = '';
        $length = strlen($html);

        while ($offset < $length && preg_match($openPattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $fullMatch = $matches[0][0];
            $openStart = $matches[0][1];
            $openEnd = $openStart + strlen($fullMatch);
            $tag = $matches[1][0];
            $attributes = $matches[2][0];

            $closing = $this->findMatchingClosingTag($html, $tag, $openEnd);
            if (null === $closing) {
                break;
            }

            $closeStart = $closing['closeStart'];
            $closeEnd = $closing['closeEnd'];
            $innerHtml = substr($html, $openEnd, $closeStart - $openEnd);
            $innerHtml = $this->replaceControlBlocksByAttribute($innerHtml, $attributeName, $builder);

            $result .= substr($html, $offset, $openStart - $offset);
            $result .= $builder($tag, $attributes, $innerHtml);

            $offset = $closeEnd;
        }

        if ($offset < $length) {
            $result .= substr($html, $offset);
        }

        return $result;
    }

    /**
     * Find matching closing tag position for a specific opening tag.
     *
     * @return array{closeStart:int, closeEnd:int}|null
     */
    private function findMatchingClosingTag(string $html, string $tag, int $offset): ?array
    {
        $pattern = '/<\/?'.preg_quote($tag, '/').'\b[^>]*>/i';
        $depth = 1;

        while (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $token = $matches[0][0];
            $start = $matches[0][1];
            $end = $start + strlen($token);

            if (str_starts_with($token, '</') || str_starts_with($token, '</'.strtoupper($tag))) {
                --$depth;
                if (0 === $depth) {
                    return ['closeStart' => $start, 'closeEnd' => $end];
                }
            } else {
                ++$depth;
            }

            $offset = $end;
        }

        return null;
    }

    /**
     * Extracts an attribute value from a raw HTML attribute string.
     */
    private function extractAttributeValue(string $attributes, string $attributeName): ?string
    {
        $pattern = '/\b'.preg_quote($attributeName, '/').'=(["\'])(.*?)\1/i';
        if (1 !== preg_match($pattern, $attributes, $matches)) {
            return null;
        }

        $value = trim($matches[2]);

        return '' === $value ? null : $value;
    }

    /**
     * Removes internal control attributes from an element attribute string.
     */
    private function stripControlAttributes(string $attributes, bool $removeIf = true): string
    {
        $cleaned = $attributes;
        $cleaned = preg_replace('/\s*\bdata-repeat=(["\']).*?\1/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*\bdata-repeat-as=(["\']).*?\1/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*\bdata-repeat-key=(["\']).*?\1/i', '', $cleaned) ?? $cleaned;
        if ($removeIf) {
            $cleaned = preg_replace('/\s*\bdata-if=(["\']).*?\1/i', '', $cleaned) ?? $cleaned;
        }
        $cleaned = preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }
}
