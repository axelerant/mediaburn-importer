<?php

/*
  Copyright (C) 2005 Alfresco, Inc.

  Licensed under the Mozilla Public License version 1.1
  with a permitted attribution clause. You may obtain a
  copy of the License at

    http://www.alfresco.org/legal/license.txt

  Unless required by applicable law or agreed to in writing,
  software distributed under the License is distributed on an
  "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND,
  either express or implied. See the License for the specific
  language governing permissions and limitations under the
  License.
*/

class AlfrescoWebService extends SoapClient
{
   private $securityExtNS = "http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd";
   private $wsUtilityNS   = "http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd";
   private $passwordType  = "http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText";

   private $ticket;
   
   public function __construct($wsdl, $options = array('trace' => true, 'exceptions' => true), $ticket = null)
   {
      // Store the current ticket
      $this->ticket = $ticket;

      // Call the base class
      parent::__construct($wsdl, $options);
   }

   public function __call($function_name, $arguments=array())
   {
      return $this->__soapCall($function_name, $arguments);
   }

   public function __soapCall($function_name, $arguments=array(), $options=array(), $input_headers=array(), $output_headers=array())
   {
      if (isset($this->ticket))
      {
         // Automatically add a security header
         $input_headers = new SoapHeader($this->securityExtNS, "Security", null, 1);
      }
      
      return parent::__soapCall($function_name, $arguments, $options, $input_headers, $output_headers);   
   }
   
   public function __doRequest($request, $location, $action, $version)
   {
      // If this request requires authentication we have to manually construct the
      // security headers.
      if (isset($this->ticket))
      { 
         $dom = new DOMDocument("1.0");
         $dom->loadXML($request);

         $securityHeader = $dom->getElementsByTagName("Security");

         if ($securityHeader->length != 1)
         {
            throw new Exception("Expected length: 1, Received: " . $securityHeader->length . ". No Security Header, or more than one element called Security!");
         }
      
         $securityHeader = $securityHeader->item(0);

         // Construct Timestamp Header
         $timeStamp = $dom->createElementNS($this->wsUtilityNS, "Timestamp");
         $createdDate = date("Y-m-d\TH:i:s\Z", mktime(date("H")+24, date("i"), date("s"), date("m"), date("d"), date("Y")));
         $expiresDate = date("Y-m-d\TH:i:s\Z", mktime(date("H")+25, date("i"), date("s"), date("m"), date("d"), date("Y")));
         $created = new DOMElement("Created", $createdDate, $this->wsUtilityNS);
         $expires = new DOMElement("Expires", $expiresDate, $this->wsUtilityNS);
         $timeStamp->appendChild($created);
         $timeStamp->appendChild($expires);

         // Construct UsernameToken Header
         $userNameToken = $dom->createElementNS($this->securityExtNS, "UsernameToken");
         $userName = new DOMElement("Username", "username", $this->securityExtNS);
         $passWord = $dom->createElementNS($this->securityExtNS, "Password");
         $typeAttr = new DOMAttr("Type", $this->passwordType);
         $passWord->appendChild($typeAttr);
         $passWord->appendChild($dom->createTextNode($this->ticket));
         $userNameToken->appendChild($userName);
         $userNameToken->appendChild($passWord);

         // Construct Security Header
         $securityHeader->appendChild($timeStamp);
         $securityHeader->appendChild($userNameToken);

         // Save the XML Request
         $request = $dom->saveXML();
      }

      return parent::__doRequest($request, $location, $action, $version);
   }
}

?>
