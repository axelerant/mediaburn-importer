<?php
require_once 'BaseObject.php';
require_once 'Node.php';

class Store extends BaseObject
{
	protected $_session;
	protected $_address;
	protected $_scheme;
	protected $_rootNode;

	public function __construct($session, $address, $scheme = "workspace")
	{
		$this->_session = $session;
		$this->_address = $address;
		$this->_scheme = $scheme;
	}

    public static function __fromString($session, $value)
    {
    	list($scheme, $address) = split("://", $value);
    	return new Store($session, $address, $scheme);	
    }

	public function __toString()
	{
		return $this->scheme . "://" . $this->address;
	}
	
	public function __toArray()
	{
		return array(
			"scheme" => $this->_scheme,
			"address" => $this->_address);
	}

	public function getAddress()
	{
		return $this->_address;
	}

	public function getScheme()
	{
		return $this->_scheme;
	}

	public function getRootNode()
	{
		if (isset ($this->_rootNode) == false)
		{
			$result = $this->_session->repositoryService->get(
				array(
					"where" => array(
						"store" => $this->__toArray())));

			$this->_rootNode = Node::createFromWebServiceData($this->_session, $result->getReturn);
		}

		return $this->_rootNode;
	}
}
?>