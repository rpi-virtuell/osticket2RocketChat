<?php
require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once('config.php');
include('Html2Text.php');

class RocketChatPlugin extends Plugin
{
	var $config_class = "RocketChatPluginConfig";
	function bootstrap()
	{
		Signal::connect('ticket.created', array(
			$this,
			'onTicketCreated'
		), 'Ticket');
		Signal::connect('threadentry.created', array(
			$this,
			'onThreadEntryCreated'
		), 'ThreadEntry');
	}
	function onThreadEntryCreated($entry)
	{
		
		if ($entry->ht[ 'type' ] == 'R') {
			// Responses by staff
			$this->onResponseCreated($entry);
		} elseif ($entry->ht[ 'type' ] == 'N') {
			// Notes by staff or system
			$this->onNoteCreated($entry);
		} else {
			// New tickets or responses by users
			$this->onMessageCreated($entry);
		}
	}
	function onResponseCreated($response)
	{
		$this->sendThreadEntryToRocketChat($response, 'Response', $this->getConfig()->get('rocketchat-color-warning'));
	}
	function onNoteCreated($note)
	{
		// $this->sendThreadEntryToRocketChat($note, 'Note', $this->getConfig()->get('rocketchat-color-good'));
	}
	function onMessageCreated($message)
	{
		// $this->sendThreadEntryToRocketChat($message, 'Message', $this->getConfig()->get('rocketchat-color-danger'));
	}
	function sendThreadEntryToRocketChat($entry, $label, $color)
	{
		global $ost;
		
		$poster = $entry->ht[ 'poster' ];
		$body = $entry->ht[ 'body' ];
		
		$thread = Thread::lookup($entry->ht[ 'thread_id' ]);
		$ticket = $thread->getObject();
		$data = $ticket->loadDynamicData();
		
		$title = $data['subject']->value;
		$ticket_id = $ticket::getIdByNumber($ticket_id);
		
		$ticketLink = $ost->getConfig()->getUrl() . 'scp/tickets.php?id=' . $ticket_id;
		
		$this->sendToRocketChat(array(
			'username' => $this->getConfig()->get('rocketchat-username'),
			'icon_emoji' => $this->getConfig()->get('rocketchat-icon_emoji'),
			'text' => ' Antwort von ' . $poster,
			'attachments' => array(
				array(
					'title' => 'Ticket #' . $ticket->getNumber() . ': ' . $title,
					'title_link' => $ticketLink,
					'text' => $this->escapeText($body),
					'color' => $color
				)
			)
		));
	}
	function onTicketCreated($ticket)
	{
		global $ost;
		
		//$id = $ticket->getLastMsgId();
		
		$number = $ticket->getNumber();
		$id = $ticket::getIdByNumber($number);
		
		$ticketLink = $ost->getConfig()->getUrl() . 'scp/tickets.php?id=' . $id;
		$title      = $ticket->getSubject() ?: 'No subject';
		$body       = $ticket->getLastMessage()->getMessage() ?: 'No content';
		
		
		
		$this->sendToRocketChat(array(
			'username' => $this->getConfig()->get('rocketchat-username'),
			'icon_emoji' => $this->getConfig()->get('rocketchat-icon_emoji'),
			'text' => 'New Ticket <' . $ticketLink . '> by ' . $ticket->getName() . ' (' . $ticket->getEmail() . ')',
			'attachments' => array(
				array(
					'title' => 'Ticket ' . $number . ': ' . $title,
					'title_link' => $ticketLink,
					'text' => $this->escapeText($body),
					'color' => $this->getConfig()->get('rocketchat-color-danger')
				)
			)
		));
	}
	function sendToRocketChat($payload)
	{
		try {
			global $ost;
			$data_string = utf8_encode(json_encode($payload));
			$url         = $this->getConfig()->get('rocketchat-webhook-url');
			$ch          = curl_init($url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($data_string)
			));
			if (curl_exec($ch) === false) {
				throw new Exception($url . ' - ' . curl_error($ch));
			} else {
				$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if ($statusCode != '200') {
					throw new Exception($url . ' Http code: ' . $statusCode);
				}
			}
			curl_close($ch);
		}
		catch (Exception $e) {
			error_log('Error posting to Rocket.Chat. ' . $e->getMessage());
		}
	}
	function escapeText($text)
	{
		$text = convert_html_to_text($text);
		if ($this->getConfig()->get('rocketchat-text-escape') == true) {
			$text = str_replace('<br />', '\n', $text);
			$text = str_replace('<br/>', '\n', $text);
			$text = str_replace('&', '&amp;', $text);
			$text = str_replace('<', '&lt;', $text);
			$text = str_replace('>', '&gt;', $text);
			$text = preg_replace("/<[^>]+>/", "", $text);
		}
		if ($this->getConfig()->get('rocketchat-text-doublenl') == true) {
			$text = preg_replace("/[\r\n]+/", "\n", $text);
			$text = preg_replace("/[\n\n]+/", "\n", $text);
		}
		$text = nl2br($text);
		$text = preg_replace('/[\n]+/', '', $text);
		if (strlen($text) >= $this->getConfig()->get('rocketchat-text-length')) {
			$text = substr($text, 0, $this->getConfig()->get('rocketchat-text-length')) . '...';
		}
		return $text;
	}
}