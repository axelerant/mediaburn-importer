<?php
/*
 * S3_Upload Test
 * Generating Signature for Amazon S3 uploads. Add your own security layer here
 * if necessary.
 */
require_once '../../Vzaar.php';
Vzaar::$token = "GETUGkPFNC84JlzXkOMSYQFTOCAixOIiroh7oUj3k";
Vzaar::$secret = "skitsanos";
Vzaar::$enableFlashSupport = true;

header('Content-type: text/xml');

echo(Vzaar::getUploadSignatureAsXml());
?>
