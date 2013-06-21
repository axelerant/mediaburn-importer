<?php
require_once 'BaseObject.php';

class SessionDetails extends BaseObject
{
	protected $_ticket;
	protected $_userName;
	protected $_repositoryURL;

	public function __construct($ticket, $userName, $repositoryURL)
	{
		$this->_ticket = $ticket;
		$this->_userName = $userName;
		$this->_repositoryURL = $repositoryURL;
	}
	
	public function getTicket()
	{
		return $this->_ticket;
	}
	
	public function getUserName()
	{
		return $this->_userName;
	}
	
	public function getRepositoryURL()
	{
		return $this->_repositoryURL;
	}
}
?>