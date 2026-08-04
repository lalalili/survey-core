<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use XMLWriter;

final class BuildDmsSoapEnvelope
{
    private const SOAP_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    private const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';

    private const SOAP_ENCODING_NAMESPACE = 'http://schemas.xmlsoap.org/soap/encoding/';

    private const DMS_NAMESPACE = 'urn:ws_CRMTicket';

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function execute(string $key, array $parameters): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNs('soapenv', 'Envelope', self::SOAP_NAMESPACE);
        $writer->writeAttribute('xmlns:xsi', self::XSI_NAMESPACE);
        $writer->writeAttribute('xmlns:xsd', self::XSD_NAMESPACE);
        $writer->writeAttribute('xmlns:urn', self::DMS_NAMESPACE);
        $writer->writeAttribute('xmlns:SOAP-ENC', self::SOAP_ENCODING_NAMESPACE);
        $writer->startElementNs('soapenv', 'Header', null);
        $writer->endElement();
        $writer->startElementNs('soapenv', 'Body', null);
        $writer->startElementNs('urn', 'ws_setTicket', null);
        $writer->writeAttributeNs('soapenv', 'encodingStyle', null, 'http://schemas.xmlsoap.org/soap/encoding/');
        $this->writeTypedString($writer, 'sKey', $key);
        $writer->startElement('vCRMTicket');
        $writer->writeAttributeNs('xsi', 'type', null, 'urn:CRMTicket');

        foreach ($parameters as $name => $value) {
            if ($name === 'TicketCategory') {
                $this->writeTicketCategories($writer, is_array($value) ? $value : []);

                continue;
            }

            if ($value !== null && ! is_array($value)) {
                $this->writeTypedString($writer, (string) $name, (string) $value);
            }
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    public function redactKey(string $xml): string
    {
        return preg_replace(
            '/(<sKey\b[^>]*>).*?(<\/sKey>)/s',
            '$1[REDACTED]$2',
            $xml,
        ) ?? $xml;
    }

    private function writeTypedString(XMLWriter $writer, string $name, string $value): void
    {
        $writer->startElement($name);
        $writer->writeAttributeNs('xsi', 'type', null, 'xsd:string');
        $writer->text($value);
        $writer->endElement();
    }

    /**
     * @param  array<int, mixed>  $categories
     */
    private function writeTicketCategories(XMLWriter $writer, array $categories): void
    {
        $categories = array_values(array_filter($categories, 'is_array'));

        $writer->startElement('category');
        $writer->writeAttributeNs('xsi', 'type', null, 'urn:ArrayOf_TicketCategory');
        $writer->writeAttributeNs(
            'SOAP-ENC',
            'arrayType',
            null,
            'urn:TicketCategory['.count($categories).']',
        );

        foreach ($categories as $category) {
            $writer->startElement('item');
            $writer->writeAttributeNs('xsi', 'type', null, 'urn:TicketCategory');
            $this->writeTypedString($writer, 'seq', (string) ($category['seq'] ?? '1'));
            $this->writeTypedString($writer, 'categorypath', (string) ($category['categorypath'] ?? ''));
            $writer->endElement();
        }

        $writer->endElement();
    }
}
