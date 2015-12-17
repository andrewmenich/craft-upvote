<?php
namespace Craft;

class Upvote_VoteService extends BaseApplicationComponent
{

	public $upvoteIcon;
	public $downvoteIcon;

	public $alreadyVoted = 'You have already voted on this element.';

	//
	public function init()
	{
		$this->_loadIcons();
	}

	//
	private function _loadIcons()
	{
		$this->upvoteIcon   = $this->_fa('caret-up');
		$this->downvoteIcon = $this->_fa('caret-down');
	}

	//
	private function _fa($starType)
	{
		return '<i class="fa fa-'.$starType.' fa-2x"></i>';
	}

	//
	public function setIcons($iconMap = array())
	{
		foreach ($iconMap as $type => $html) {
			switch ($type) {
				case 'up'   : $this->upvoteIcon   = $html; break;
				case 'down' : $this->downvoteIcon = $html; break;
			}
		}
	}

	//
	public function castVote($elementId, $vote)
	{

		// If login is required
		if (craft()->upvote->settings['requireLogin']) {
			// Update user history
			if (!$this->_updateUserHistoryDatabase($elementId, $vote)) {
				return $this->alreadyVoted;
			}
		} else {
			// Update user cookie
			if (!$this->_updateUserHistoryCookie($elementId, $vote)) {
				return $this->alreadyVoted;
			}
		}

		// Update element tally
		$this->_updateElementTally($elementId, $vote);
		$this->_updateVoteLog($elementId, $vote);

		return array(
			'id'   => $elementId,
			'vote' => $vote,
		);

	}

	//
	private function _updateUserHistoryDatabase($elementId, $vote)
	{
		$user = craft()->userSession->getUser();
		// If user is not logged in, return false
		if (!$user) {
			return false;
		}
		// Load existing element history
		$record = Upvote_UserHistoryRecord::model()->findByPK($user->id);
		// If no history exists, create new
		if (!$record) {
			$record = new Upvote_UserHistoryRecord;
			$record->id = $user->id;
			$history = array();
		// Else if user already voted on element, return false
		} else if (array_key_exists($elementId, $record->history)) {
			return false;
		// Else, add vote to history
		} else {
			$history = $record->history;
		}
		// Register vote
		$history[$elementId] = $vote;
		$record->history = $history;
		// Save
		return $record->save();
	}

	//
	private function _updateUserHistoryCookie($elementId, $vote)
	{
		$history =& craft()->upvote->anonymousHistory;
		// If not already voted for, cast vote
		if (!array_key_exists($elementId, $history)) {
			$history[$elementId] = $vote;
			$this->_saveUserHistoryCookie();
			return true;
		} else {
			return false;
		}

	}

	//
	private function _saveUserHistoryCookie()
	{
		$cookie   = craft()->upvote->userCookie;
		$history  = craft()->upvote->anonymousHistory;
		$lifespan = craft()->upvote->userCookieLifespan;
		craft()->userSession->saveCookie($cookie, $history, $lifespan);
	}

	//
	private function _updateElementTally($elementId, $vote)
	{
		// Load existing element tally
		$record = Upvote_ElementTallyRecord::model()->findByPK($elementId);
		// If no tally exists, create new
		if (!$record) {
			$record = new Upvote_ElementTallyRecord;
			$record->id = $elementId;
			$record->tally = 0;
		}
		// Register vote
		$record->tally += $vote;
		// Save
		return $record->save();
	}

	//
	private function _updateVoteLog($elementId, $vote, $unvote = false)
	{
		if (craft()->upvote->settings['keepVoteLog']) {
			$currentUser = craft()->userSession->getUser();
			$record = new Upvote_VoteLogRecord;
			$record->elementId = $elementId;
			$record->userId    = ($currentUser ? $currentUser->id : null);
			$record->ipAddress = $_SERVER['REMOTE_ADDR'];
			$record->voteValue = $vote;
			$record->wasUnvote = (int) $unvote;
			$record->save();
		}
	}

	//
	public function removeVote($elementId)
	{
		$originalVote = false;

		$this->_removeVoteFromCookie($elementId, $originalVote);
		$this->_removeVoteFromDb($elementId, $originalVote);

		if ($originalVote) {
			$antivote = (-1 * $originalVote);
			$this->_updateElementTally($elementId, $antivote);
			$this->_updateVoteLog($elementId, $antivote, true);
			return array(
				'id'       => $elementId,
				'antivote' => $antivote,
			);
		} else {
			return 'Unable to remove vote.';
		}

	}

	//
	private function _removeVoteFromCookie($elementId, &$originalVote)
	{
		// Remove from cookie history
		$historyCookie =& craft()->upvote->anonymousHistory;
		if (array_key_exists($elementId, $historyCookie)) {
			$originalVote = $historyCookie[$elementId];
			unset($historyCookie[$elementId]);
			$this->_saveUserHistoryCookie();
		}
	}

	//
	private function _removeVoteFromDb($elementId, &$originalVote)
	{
		$user = craft()->userSession->getUser();
		if ($user) {
			$record = Upvote_UserHistoryRecord::model()->findByPK($user->id);
			if ($record) {
				// Remove from database history
				$historyDb = $record->history;
				if (array_key_exists($elementId, $historyDb)) {
					if (!$originalVote) {
						$originalVote = $historyDb[$elementId];
					}
					unset($historyDb[$elementId]);
					$record->history = $historyDb;
					$record->save();
				}
			}
		}
	}

}