<?php
require_once '../../Vzaar.php';
Vzaar::$token = "GETUGkPFNC84JlzXkOMSYQFTOCAixOIiroh7oUj3k";
Vzaar::$secret = "skitsanos";

header('Content-type: text/html');

if (isset($_POST['guid'])) {
    $apireply = Vzaar::processVideo($_POST['guid'], $_POST['title'], $_POST['description'], 1);
    echo($apireply);
}
else
{
    echo('GUID is missing');
}
?>