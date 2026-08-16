<?php

/**
 * SOGo <-> vCard 4.0 (RFC 6350) Replikations-Middleware
 * Version 2.0 - Mit HTTP-Condition- und Token-Absicherung
 */
class SogoCardDavMiddleware 
{
    private string $sogoBackendUrl;

    public function __construct()
    {
        if (!isset($_SERVER['SOGO_BACKEND_URL']) || empty($_SERVER['SOGO_BACKEND_URL'])) {
            throw new RuntimeException('SOGO_BACKEND_URL is not set.');
        }
        $this->sogoBackendUrl = $_SERVER['SOGO_BACKEND_URL'];
    }

    private function logDebug(string $message, $data = null): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message";
        if ($data !== null) {
            $logEntry .= "\n" . print_r($data, true);
        }
        $logFile = '/tmp/sogo_middleware_debug.log';
        @file_put_contents($logFile, $logEntry . "\n\n", FILE_APPEND);
    }

    /**
     * Escaped einen vCard-Wert nach RFC 6350
     * Escape: \ , ; Newline
     */
    private function escapeVCardValue(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(',', '\\,', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace("\r", '', $value);
        return $value;
    }

    private function unescapeVCardValue(string $value): string
    {
        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\N', "\n", $value);
        $value = str_replace('\\;', ';', $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\\\', '\\', $value); // Backslash zuletzt!
        return $value;
    }

    private function escapeVListParameter(string $value): string
    {
        if (preg_match('/[,;:]/', $value)) {
            $value = str_replace('\\', '\\\\', $value);
            $value = str_replace('"', '\\"', $value);
            return '"' . $value . '"';
        }
        return $value;
    }

    private function unescapeVListParameter(string $value): string
    {
        if (preg_match('/^"(.*)"$/', $value, $match)) {
            $value = $match[1];
            $value = str_replace('\\"', '"', $value);
            $value = str_replace('\\\\', '\\', $value);
        }
        return $value;
    }

    public function handleRequest(): void 
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestBody = file_get_contents('php://input');

        if ($requestMethod === 'PUT' && (stripos($requestBody, 'KIND:group') !== false || stripos($requestBody, 'X-ADDRESSBOOKSERVER-KIND:group') !== false)) {
            $requestBody = $this->rfc6350ToSogoVlist($requestBody);
        }

        $response = $this->forwardToSogo($requestBody);

        $statusCode = $response['status_code'];
        $contentType = $response['headers']['content-type'] ?? '';
        $responseBody = $response['body'];

        if ($statusCode === 412) {
            $this->sendFinalResponse($statusCode, $response['headers'], $responseBody);
            return;
        }

        if (!empty($responseBody) && (stripos($contentType, 'xml') !== false || stripos(trim($responseBody), '<?xml') === 0)) {
            $responseBody = $this->processXmlResponse($responseBody);
        }
        elseif (stripos($responseBody, 'BEGIN:VLIST') !== false) {
            $responseBody = $this->sogoVlistToRfc6350($responseBody);
        }

        $this->sendFinalResponse($statusCode, $response['headers'], $responseBody);
    }

    private function processXmlResponse(string $xmlString): string 
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($xmlString, LIBXML_NOBLANKS | LIBXML_NOCDATA)) {
            $this->logDebug("Could not load XML form body, no processing performed");
            return $xmlString; 
        }

        $xpath = new DOMXPath($dom);
        $addressDataNodes = $xpath->query('//*[local-name()="address-data"]');

        if ($addressDataNodes && $addressDataNodes->length > 0) {
            foreach ($addressDataNodes as $node) {
                $currentData = $node->nodeValue;

                if (stripos($currentData, 'BEGIN:VLIST') !== false) {
                    while ($node->hasChildNodes()) {
                        $node->removeChild($node->firstChild);
                    }

                    $vcard = $this->sogoVlistToRfc6350($currentData);
                    $node->appendChild($dom->createCDATASection($vcard));
                }
            }
            return $dom->saveXML();
        }

        return $xmlString;
    }

    private function rfc6350ToSogoVlist(string $vcard): string 
    {
        $this->logDebug("=== vCard -> VLIST INPUT ===", $vcard);

        $vcard = preg_replace('/[\r\n]+[ \t]/', '', $vcard); // Unfolding

        preg_match('/^UID:(.+)$/mi', $vcard, $uidMatch);
        preg_match('/^FN:(.+)$/mi', $vcard, $fnMatch);
        preg_match('/^REV:(.+)$/mi', $vcard, $revMatch);
        preg_match_all('/^(?:MEMBER|X-ADDRESSBOOKSERVER-MEMBER):(.+)$/mi', $vcard, $memberMatches);

        $uid = isset($uidMatch[1]) ? trim($uidMatch[1]) : null;
        $fn = isset($fnMatch[1]) ? trim($fnMatch[1]) : 'Gruppe';

        $vlist = [];
        $vlist[] = "BEGIN:VLIST";
        $vlist[] = "VERSION:1.0";
        $vlist[] = "UID:$uid";
        $vlist[] = "FN:$fn";
        if (isset($revMatch[1])) {
            $vlist[] = "REV:" . trim($revMatch[1]);
        }

        if (!empty($memberMatches[1])) {
            foreach ($memberMatches[1] as $index => $member) {
                $member = trim($member);

                // TODO Den COde cann keine Sau lesen
                // Extrahiere mailto: oder urn:uuid:
                $cleanUid = preg_replace('/^(urn:uuid:|mailto:)/i', '', $member);

                // Fall 1: mailto: - direkt E-Mail
                if (filter_var($cleanUid, FILTER_VALIDATE_EMAIL)) {
                    $emailParam = $this->escapeVListParameter($cleanUid);
                    $card = "CARD;EMAIL={$emailParam};FN={$emailParam}:{$cleanUid}";
                    $vlist[] = $card;
                }
                // Fall 2: urn:uuid: - Kontakt vom CardDAV-Server abrufen
                else {
                    $contactFilename = (stripos($cleanUid, '.vcf') === false) ? ($cleanUid . '.vcf') : $cleanUid;
                    $contactData = $this->fetchContactByUuid($cleanUid);

                    if ($contactData && isset($contactData['email'])) {
                        $emailParam = $this->escapeVListParameter($contactData['email']);
                        $fnParam = $this->escapeVListParameter($contactData['fn'] ?? $contactData['email']);
                        $card = "CARD;EMAIL={$emailParam};FN={$fnParam}:{$contactFilename}";
                        $vlist[] = $card;
                    } else {
                        // SKIP statt leeres CARD zu senden!
                        $this->logDebug("  -> SKIPPED! Could not resolve contact $cleanUid - SOGo needs EMAIL+FN!");
                    }
                }
            }
        }

        $vlist[] = "END:VLIST";
        $result = implode("\r\n", $vlist);
        $this->logDebug("=== vCard -> VLIST OUTPUT ===", $result);
        return $result;
    }

    private function sogoVlistToRfc6350(string $vlist): string 
    {
        $this->logDebug("=== VLIST -> vCard INPUT ===", $vlist);

        $vlist = preg_replace('/[\r\n]+[ \t]/', '', $vlist); // Unfolding

        preg_match('/^UID:(.+)$/mi', $vlist, $uidMatch);
        preg_match('/^FN:(.+)$/mi', $vlist, $fnMatch);
        preg_match('/^REV:(.+)$/mi', $vlist, $revMatch);
        preg_match_all('/^CARD([^:]*):(.+)$/mi', $vlist, $matches);

        // Pflichtfelder extrahieren
        $uid = isset($uidMatch[1]) ? trim($uidMatch[1]) : null;
        $fn = isset($fnMatch[1]) ? trim($fnMatch[1]) : 'Gruppe';

        $vcard = [];
        $vcard[] = "BEGIN:VCARD";
        $vcard[] = "VERSION:3.0";
        $vcard[] = "UID:$uid";

        $vcard[] = "FN:$fn";
        $vcard[] = "N:;;;;";
        $vcard[] = "X-ADDRESSBOOKSERVER-KIND:group";

        if (isset($revMatch[1])) {
            $vcard[] = "REV:" . trim($revMatch[1]);
        }

        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $targetFilename) {
                $targetFilename = trim($targetFilename);
                $params = trim($matches[1][$index]);

                $contactData = $this->fetchContactByUuid($targetFilename);
                if ($contactData && isset($contactData['uid'])) {
                    $actualUid = $this->escapeVCardValue($contactData['uid']);
                    $member = "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:" . $actualUid;
                    $vcard[] = $member;
                }
                else {
                    $this->logDebug("WARNING: Could not get UID for filename: $targetFilename - skipping");
                    continue;
                }
            }
        }

        $vcard[] = "END:VCARD";
        $result = implode("\r\n", $vcard);
        $this->logDebug("=== VLIST -> vCard OUTPUT ===", $result);
        return $result;
    }

    private function fetchContactByUuid(string $uuid): ?array
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#^(.*/)([^/]*)$#', $requestUri, $matches)) {
            $basePath = $matches[1];
        } else {
            $this->logDebug("Could not extract base path from URI: $requestUri");
            return null;
        }

        $contactFilename = (stripos($uuid, '.vcf') === false) ? ($uuid . '.vcf') : $uuid;
        $contactUri = $basePath . $contactFilename;

        $response = $this->internalSogoRequest('GET', $contactUri);

        if ($response['status_code'] === 200 && !empty($response['body'])) {
            $result = $this->extractContactData($response['body']);
            return $result;
        }

        return null;
    }

    private function extractContactData(string $vcard): ?array
    {
        // Unfolding
        $vcard = preg_replace('/[\r\n]+[ \t]/', '', $vcard);

        preg_match('/^FN:(.+)$/mi', $vcard, $fnMatch);
        preg_match('/^EMAIL[^:]*:(.+)$/mi', $vcard, $emailMatch);
        preg_match('/^UID[^:]*:(.+)$/mi', $vcard, $uidMatch);

        $email = isset($emailMatch[1]) ? $this->unescapeVCardValue(trim($emailMatch[1])) : null;
        $fn = isset($fnMatch[1]) ? $this->unescapeVCardValue(trim($fnMatch[1])) : null;
        $uid = isset($uidMatch[1]) ? $this->unescapeVCardValue(trim($uidMatch[1])) : null;

        if ($email || $fn || $uid) {
            return [
                'email' => $email,
                'fn' => $fn,
                'uid' => $uid
            ];
        }
        return null;
    }

    private function internalSogoRequest(string $method, string $uri, string $body = '', string $contentType = ''): array
    {
        $ch = curl_init($this->sogoBackendUrl . $uri);

        $headers = [];

        $allowedHeaders = ['authorization'];

        foreach (getallheaders() as $name => $value) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, $allowedHeaders)) {
                $headers[] = "$name: $value";
            }
        }

        if ($contentType !== '') {
            $headers[] = "Content-Type: $contentType";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseHeadersText = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        $responseHeaders = [];
        foreach (explode("\r\n", $responseHeadersText) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    private function forwardToSogo(string $body): array 
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        $ch = curl_init($this->sogoBackendUrl . $uri);
        
        $headers = [];
        foreach (getallheaders() as $name => $value) {
            $lowerName = strtolower($name);
            if ($lowerName === 'content-length') {
                continue;
            }
            $headers[] = "$name: $value";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); 
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseHeadersText = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        $responseHeaders = [];
        foreach (explode("\r\n", $responseHeadersText) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    private function sendFinalResponse(int $statusCode, array $headers, string $body): void 
    {
        http_response_code($statusCode);
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), ['content-length', 'connection', 'keep-alive', 'transfer-encoding'])) {
                continue; 
            }
            header("$name: $value");
        }

        header("Content-Length: " . strlen($body));
        echo $body;
    }
}

$middleware = new SogoCardDavMiddleware();
$middleware->handleRequest();
