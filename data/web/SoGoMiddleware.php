<?php

/**
 * SOGo <-> vCard 4.0 (RFC 6350) Replikations-Middleware
 * Version 2.0 - Mit HTTP-Condition- und Token-Absicherung
 */
class SogoCardDavMiddleware 
{
    // Die URL deines echten SOGo-Servers intern
    // TODO PACK THIS INTO CONFIG FILE
    private const SOGO_BACKEND_URL = 'http://sogo:20000'; 

    private function logDebug(string $message, $data = null): void
    {
        // Versuche verschiedene Log-Locations
        $logLocations = [
            '/var/log/sogo_middleware_debug.log',
            '/tmp/sogo_middleware_debug.log',
            __DIR__ . '/sogo_middleware_debug.log'
        ];

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message";
        if ($data !== null) {
            $logEntry .= "\n" . print_r($data, true);
        }

        foreach ($logLocations as $logFile) {
            if (@file_put_contents($logFile, $logEntry . "\n\n", FILE_APPEND) !== false) {
                return; // Erfolgreich geschrieben
            }
        }

        // Fallback: error_log wenn alles fehlschlägt
        error_log("SoGoMiddleware: $message");
    }

    public function handleRequest(): void 
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestBody = file_get_contents('php://input');

        $this->logDebug("=== REQUEST: $requestMethod $requestUri ===");

        // 1. CLIENT -> SERVER PROTECTION (Schreibzugriffe absichern)
        // Falls der Client Bedingungen (ETags/Tokens) mitsendet, lesen wir diese aus,
        // um sicherzustellen, dass sie unverändert an SOGo übermittelt werden.
        $ifHeader = $_SERVER['HTTP_IF'] ?? null;
        $ifMatchHeader = $_SERVER['HTTP_IF_MATCH'] ?? null;

        // Wenn ein Client eine Gruppe hochlädt (vCard 3.0 oder 4.0), konvertieren wir sie für SOGo in VLIST
        if ($requestMethod === 'PUT' && (stripos($requestBody, 'KIND:group') !== false || stripos($requestBody, 'X-ADDRESSBOOKSERVER-KIND:group') !== false)) {
            $this->logDebug("Converting GROUP to VLIST", substr($requestBody, 0, 500));
            $requestBody = $this->rfc6350ToSogoVlist($requestBody);
            $this->logDebug("Converted VLIST", $requestBody);
        }

        // 2. REQUEST AN SOGO SERVER WEITERLEITEN
        $response = $this->forwardToSogo($requestMethod, $requestUri, $requestBody, $ifHeader, $ifMatchHeader);

        // 3. SERVER -> CLIENT PROTECTION (Lesezugriffe & Sync-Antworten)
        $statusCode = $response['status_code'];
        $contentType = $response['headers']['content-type'] ?? '';
        $responseBody = $response['body'];

        $this->logDebug("Response Status: $statusCode, Content-Type: $contentType");

        // Replikations-Schutz: Falls SOGo wegen einer fehlgeschlagenen Bedingung mit 412 Precondition Failed 
        // antwortet, reichen wir diesen Status sofort unverändert durch, um Sync-Konflikte sauber im Client zu triggern.
        if ($statusCode === 412) {
            $this->sendFinalResponse($statusCode, $response['headers'], $responseBody);
            return;
        }

        // Fall A: Massen-Synchronisation über XML (REPORT / PROPFIND)
        // Prüfe ob Antwort XML enthält (unabhängig vom Content-Type Header)
        if (!empty($responseBody) && (stripos($contentType, 'xml') !== false || stripos(trim($responseBody), '<?xml') === 0)) {
            $this->logDebug("Processing XML response");
            $responseBody = $this->processXmlResponse($responseBody);
        }
        // Fall B: Einzelabruf einer Gruppe über GET (nur wenn NICHT bereits als XML verarbeitet)
        elseif (stripos($responseBody, 'BEGIN:VLIST') !== false) {
            $this->logDebug("Converting VLIST to vCard", substr($responseBody, 0, 500));
            $responseBody = $this->sogoVlistToRfc6350($responseBody);
            $this->logDebug("Converted vCard", $responseBody);
        }

        // 4. ANTWORT AUSLIEFERN
        $this->sendFinalResponse($statusCode, $response['headers'], $responseBody);
    }

    /**
     * Durchsucht WebDAV-XML-Antworten nach <address-data> und konvertiert SOGo VLISTs
     */
    private function processXmlResponse(string $xmlString): string 
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Optionale Flags zur Vermeidung von XML-Injections und für sauberes Handling von CDATA
        if (!$dom->loadXML($xmlString, LIBXML_NOBLANKS | LIBXML_NOCDATA)) {
            return $xmlString; 
        }

        $xpath = new DOMXPath($dom);
        $addressDataNodes = $xpath->query('//*[local-name()="address-data"]');

        if ($addressDataNodes && $addressDataNodes->length > 0) {
            $this->logDebug("Found {$addressDataNodes->length} address-data nodes in XML");

            foreach ($addressDataNodes as $node) {
                $currentData = $node->nodeValue;

                if (stripos($currentData, 'BEGIN:VLIST') !== false) {
                    $this->logDebug("Converting VLIST in XML", substr($currentData, 0, 300));
                    $vcard4 = $this->sogoVlistToRfc6350($currentData);

                    // Bestehende Kindknoten löschen, um XML-Integrität zu wahren
                    while ($node->hasChildNodes()) {
                        $node->removeChild($node->firstChild);
                    }
                    // Als CDATA einbetten, damit vCard-Zeilenumbrüche das XML nicht korrumpieren
                    $node->appendChild($dom->createCDATASection($vcard4));
                    $this->logDebug("Converted vCard in XML", substr($vcard4, 0, 300));
                }
            }
            }
            return $dom->saveXML();
        }

        return $xmlString;
    }

    /**
     * Konverter Logik: RFC 6350 (vCard 3.0/4.0 mit Apple Extensions) Gruppe -> SOGo VLIST
     */
    private function rfc6350ToSogoVlist(string $vcard): string 
    {
        $vcard = preg_replace('/[\r\n]+[ \t]/', '', $vcard); // Unfolding

        preg_match('/^UID:(.+)$/mi', $vcard, $uidMatch);
        preg_match('/^FN:(.+)$/mi', $vcard, $fnMatch);
        preg_match('/^REV:(.+)$/mi', $vcard, $revMatch);

        // Unterstütze sowohl vCard 4.0 als auch Apple Extensions (vCard 3.0)
        preg_match_all('/^(?:MEMBER|X-ADDRESSBOOKSERVER-MEMBER):(.+)$/mi', $vcard, $memberMatches);

        $uid = isset($uidMatch[1]) ? trim($uidMatch[1]) : md5(time() . uniqid());
        $fn = isset($fnMatch[1]) ? trim($fnMatch[1]) : 'Gruppe';

        $vlist = ["BEGIN:VLIST", "VERSION:1.0", "UID:$uid", "FN:$fn"];
        if (isset($revMatch[1])) {
            $vlist[] = "REV:" . trim($revMatch[1]);
        }

        if (!empty($memberMatches[1])) {
            foreach ($memberMatches[1] as $member) {
                $cleanUid = preg_replace('/^(urn:uuid:|mailto:)/i', '', trim($member));
                if (filter_var($cleanUid, FILTER_VALIDATE_EMAIL)) {
                    $vlist[] = "CARD;EMAIL={$cleanUid};FN={$cleanUid}:{$cleanUid}";
                } else {
                    $vlist[] = "CARD:{$cleanUid}";
                }
            }
        }
        $vlist[] = "END:VLIST";
        return implode("\r\n", $vlist);
    }

    /**
     * Konverter Logik: SOGo VLIST -> vCard 3.0 mit Apple Extensions (Roundcube-kompatibel)
     */
    private function sogoVlistToRfc6350(string $vlist): string 
    {
        $vlist = preg_replace('/[\r\n]+[ \t]/', '', $vlist); // Unfolding

        preg_match('/^UID:(.+)$/mi', $vlist, $uidMatch);
        preg_match('/^FN:(.+)$/mi', $vlist, $fnMatch);
        preg_match('/^REV:(.+)$/mi', $vlist, $revMatch);
        preg_match_all('/^CARD([^:]*):(.+)$/mi', $vlist, $matches);

        // Pflichtfelder extrahieren
        $uid = isset($uidMatch[1]) ? trim($uidMatch[1]) : null;
        $fn = isset($fnMatch[1]) ? trim($fnMatch[1]) : 'Gruppe';

        // vCard 3.0 mit Apple Extensions (Roundcube/Sabre-kompatibel)
        $vcard = [];
        $vcard[] = "BEGIN:VCARD";
        $vcard[] = "VERSION:3.0";

        if ($uid) {
            $vcard[] = "UID:$uid";
        }

        $vcard[] = "FN:$fn";
        $vcard[] = "N:;;;;";
        $vcard[] = "X-ADDRESSBOOKSERVER-KIND:group";

        // Optionale Felder
        if (isset($revMatch[1])) {
            $vcard[] = "REV:" . trim($revMatch[1]);
        }

        // Mitglieder konvertieren
        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $targetUid) {
                $targetUid = trim($targetUid);
                $params = $matches[1][$index];

                if (preg_match('/EMAIL=([^;:]+)/i', $params, $emailMatch)) {
                    $vcard[] = "X-ADDRESSBOOKSERVER-MEMBER:mailto:" . trim($emailMatch[1]);
                } elseif (filter_var($targetUid, FILTER_VALIDATE_EMAIL)) {
                    $vcard[] = "X-ADDRESSBOOKSERVER-MEMBER:mailto:" . $targetUid;
                } else {
                    $vcard[] = "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:" . $targetUid;
                }
            }
        }

        $vcard[] = "END:VCARD";
        return implode("\r\n", $vcard);
    }

    /**
     * Reicht den Request an SOGo weiter und sorgt für den Erhalt aller Replikations-Header
     */
    private function forwardToSogo(string $method, string $uri, string $body, ?string $ifHeader, ?string $ifMatchHeader): array 
    {
        $ch = curl_init(self::SOGO_BACKEND_URL . $uri);
        
        $headers = [];
        foreach (getallheaders() as $name => $value) {
            $lowerName = strtolower($name);
            // Content-Length ausschließen (wird von cURL für den manipulierten Body neu berechnet)
            if ($lowerName === 'content-length') {
                continue;
            }
            $headers[] = "$name: $value";
        }

        // Explizite Absicherung für WebDAV Status-Konditionen (falls nicht in getallheaders erfasst)
        if ($ifHeader && !in_array("If: $ifHeader", $headers)) {
            $headers[] = "If: $ifHeader";
        }
        if ($ifMatchHeader && !in_array("If-Match: $ifMatchHeader", $headers)) {
            $headers[] = "If-Match: $ifMatchHeader";
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
                // Speichere Header-Namen in lowercase für case-insensitive Zugriff
                $responseHeaders[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    /**
     * Sendet die modifizierten Daten sicher zurück an den Client
     */
    private function sendFinalResponse(int $statusCode, array $headers, string $body): void 
    {
        http_response_code($statusCode);
        foreach ($headers as $name => $value) {
            // SOGos Längenberechnung verwerfen, da wir die Payload-Größe verändert haben
            if (in_array(strtolower($name), ['content-length', 'connection', 'keep-alive', 'transfer-encoding'])) {
                continue; 
            }
            header("$name: $value");
        }

        // Berechne Content-Length auf Basis des finalen vCard 4 / XML-Strings
        header("Content-Length: " . strlen($body));
        echo $body;
    }
}

// Initialisierung der Middleware
$middleware = new SogoCardDavMiddleware();
$middleware->handleRequest();
