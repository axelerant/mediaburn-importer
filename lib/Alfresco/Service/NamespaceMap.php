<?php

class NamespaceMap
{
	public static $namespaceMap = array(
		"d" => "http://www.alfresco.org/model/dictionary/1.0",
		"sys" => "http://www.alfresco.org/model/system/1.0",
		"cm" => "http://www.alfresco.org/model/content/1.0",
		"app" => "http://www.alfresco.org/model/application/1.0",
		"bpm" => "http://www.alfresco.org/model/bpm/1.0");
	
	/**
	 * Tests whether this is the short name
	 */
	public static function isShortName($shortName)
	{
		$result = false;
		$charCount = count_chars($shortName, 1);
		$char = ord("_");
		if (array_key_exists($char, $charCount) == true)
		{
			$result = true;
		}
		return $result;	
	}
	
	public static function getFullName($shortName)
	{		
		$result = null;
		if (NamespaceMap::isShortName($shortName) == true)
		{
			list($prefix, $name) = NamespaceMap::splitShortName($shortName);							
			$url = NamespaceMap::$namespaceMap[$prefix];
			if ($url != null)
			{
				$result = "{".$url."}".$name;
			}
		}
		return $result;
	}	 
	
	private static function splitShortName($shortName)
	{
		$parts = explode("_", $shortName);
		$index = 0;
		$remainder = "";
		foreach($parts as $part)
		{
			if ($index > 0)
			{
				if ($index > 1)
				{
					// Convert the _'s to -'s
					$remainder .= "-";	
				}
				$remainder .= $part;
			}
			$index++;
		}	
		return array($parts[0], $remainder);	
	}   
}

?>
