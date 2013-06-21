<?php

require_once 'AlfrescoWebService.php';

class WebServiceFactory
{
   public static function getAuthenticationService($path)
   {
        $path .= '/AuthenticationService?wsdl';
        return new AlfrescoWebService($path, array());
   }

   public static function getRepositoryService($path, $ticket)
   {
        $path .= '/RepositoryService?wsdl';
        return new AlfrescoWebService($path, array(), $ticket);
   }
   
   public static function getContentService($path, $ticket)
   {
        $path .= '/ContentService?wsdl';
        return new AlfrescoWebService($path, array(), $ticket);
   }
}

?>