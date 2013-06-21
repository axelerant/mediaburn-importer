<?php
/*
 * @author skitsanos
 */

require_once '../Vzaar.php';


Vzaar::$secret = 'sNqgt3jvICeQxmZlrqg8NrdvbrXwdccJydGygiS2q';
Vzaar::$token = 'skitsanos';

if (isset($_GET['id']))
{
    $res=Vzaar::deleteVideo($_GET['id']);
    print_r($res);
}
?>
<form method='get'>
Video Id: <input type='text' name='id' value='0'/>
<input type='submit'/>
</form>
