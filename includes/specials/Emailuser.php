<?php
/**
 * @file
 * @ingroup SpecialPage
 */

/**
 * @todo document
 */
function wfSpecialEmailuser( $par ) {
	global $wgRequest, $wgUser, $wgOut;

	$action = $wgRequest->getVal( 'action' );
	$target = isset($par) ? $par : $wgRequest->getVal( 'target' );
	$targetUser = EmailUserForm::validateEmailTarget( $target );
	
	if ( !( $targetUser instanceof User ) ) {
		$wgOut->showErrorPage( $targetUser[0], $targetUser[1] );
		return;
	}
	
	$form = new EmailUserForm( $targetUser,
			$wgRequest->getText( 'wpText' ),
			$wgRequest->getText( 'wpSubject' ),
			$wgRequest->getBool( 'wpCCMe' ) );
	if ( $action == 'success' ) {
		$form->showSuccess();
		return;
	}
					
	$error = EmailUserForm::getPermissionsError( $wgUser, $wgRequest->getVal( 'wpEditToken' ) );
	if ( $error ) {
		switch ( $error[0] ) {
			case 'blockedemailuser':
				$wgOut->blockedPage();
				return;
			case 'actionthrottledtext':
				$wgOut->rateLimited();
				return;
			case 'sessionfailure':
				$form->showForm();
				return;
			default:
				$wgOut->showErrorPage( $error[0], $error[1] );
				return;
		}
	}	
		
	
	if ( "submit" == $action && $wgRequest->wasPosted() ) {
		$result = $form->doSubmit();
		
		if ( !is_null( $result ) ) {
			$wgOut->addHTML( wfMsg( "usermailererror" ) .
					' ' . htmlspecialchars( $result->getMessage() ) );
		} else {
			$titleObj = SpecialPage::getTitleFor( "Emailuser" );
			$encTarget = wfUrlencode( $form->getTarget()->getName() );
			$wgOut->redirect( $titleObj->getFullURL( "target={$encTarget}&action=success" ) );
		}
	} else {
		$form->showForm();
	}
}

/**
 * Implements the Special:Emailuser web interface, and invokes userMailer for sending the email message.
 * @ingroup SpecialPage
 */
class EmailUserForm {

	var $target;
	var $text, $subject;
	var $cc_me;     // Whether user requested to be sent a separate copy of their email.

	/**
	 * @param User $target
	 */
	function EmailUserForm( $target, $text, $subject, $cc_me ) {
		$this->target = $target;
		$this->text = $text;
		$this->subject = $subject;
		$this->cc_me = $cc_me;
	}

	function showForm() {
		global $wgOut, $wgUser;
		$skin = $wgUser->getSkin();

		$wgOut->setPagetitle( wfMsg( "emailpage" ) );
		$wgOut->addWikiMsg( "emailpagetext" );

		if ( $this->subject === "" ) {
			$this->subject = wfMsgForContent( "defemailsubject" );
		}

		$emf = wfMsg( "emailfrom" );
		$senderLink = $skin->makeLinkObj(
			$wgUser->getUserPage(), htmlspecialchars( $wgUser->getName() ) );
		$emt = wfMsg( "emailto" );
		$recipientLink = $skin->makeLinkObj(
			$this->target->getUserPage(), htmlspecialchars( $this->target->getName() ) );
		$emr = wfMsg( "emailsubject" );
		$emm = wfMsg( "emailmessage" );
		$ems = wfMsg( "emailsend" );
		$emc = wfMsg( "emailccme" );
		$encSubject = htmlspecialchars( $this->subject );

		$titleObj = SpecialPage::getTitleFor( "Emailuser" );
		$action = $titleObj->escapeLocalURL( "target=" .
			urlencode( $this->target->getName() ) . "&action=submit" );
		$token = htmlspecialchars( $wgUser->editToken() );

		$wgOut->addHTML( "
<form id=\"emailuser\" method=\"post\" action=\"{$action}\">
<table border='0' id='mailheader'><tr>
<td align='right'>{$emf}:</td>
<td align='left'><strong>{$senderLink}</strong></td>
</tr><tr>
<td align='right'>{$emt}:</td>
<td align='left'><strong>{$recipientLink}</strong></td>
</tr><tr>
<td align='right'>{$emr}:</td>
<td align='left'>
<input type='text' size='60' maxlength='200' name=\"wpSubject\" value=\"{$encSubject}\" />
</td>
</tr>
</table>
<span id='wpTextLabel'><label for=\"wpText\">{$emm}:</label><br /></span>
<textarea id=\"wpText\" name=\"wpText\" rows='20' cols='80' style=\"width: 100%;\">" . htmlspecialchars( $this->text ) .
"</textarea>
" . wfCheckLabel( $emc, 'wpCCMe', 'wpCCMe', $wgUser->getBoolOption( 'ccmeonemails' ) ) . "<br />
<input type='submit' name=\"wpSend\" value=\"{$ems}\" />
<input type='hidden' name='wpEditToken' value=\"$token\" />
</form>\n" );

	}

	/*
	 * Really send a mail. Permissions should have been checked using 
	 * EmailUserForm::getPermissionsError. It is probably also a good idea to
	 * check the edit token and ping limiter in advance.
	 */
	function doSubmit() {
		global $wgUser, $wgUserEmailUseReplyTo, $wgSiteName;

		$to = new MailAddress( $this->target );
		$from = new MailAddress( $wgUser );
		$subject = $this->subject;

		$prefsTitle = Title::newFromText( 'Preferences', NS_SPECIAL );
		
		// Add a standard footer
		$footerArgs[0] = $from->name;
		$footerArgs[1] = $to->name;
		$footerArgs[2] = $prefsTitle->getFullURL();
		$this->text = $this->text . "\n" . wfMsgExt( 'emailuserfooter', 'parsemag', $footerArgs );
		
		if( wfRunHooks( 'EmailUser', array( &$to, &$from, &$subject, &$this->text ) ) ) {

			if( $wgUserEmailUseReplyTo ) {
				// Put the generic wiki autogenerated address in the From:
				// header and reserve the user for Reply-To.
				//
				// This is a bit ugly, but will serve to differentiate
				// wiki-borne mails from direct mails and protects against
				// SPF and bounce problems with some mailers (see below).
				global $wgPasswordSender;
				$mailFrom = new MailAddress( $wgPasswordSender );
				$replyTo = $from;
			} else {
				// Put the sending user's e-mail address in the From: header.
				//
				// This is clean-looking and convenient, but has issues.
				// One is that it doesn't as clearly differentiate the wiki mail
				// from "directly" sent mails.
				//
				// Another is that some mailers (like sSMTP) will use the From
				// address as the envelope sender as well. For open sites this
				// can cause mails to be flunked for SPF violations (since the
				// wiki server isn't an authorized sender for various users'
				// domains) as well as creating a privacy issue as bounces
				// containing the recipient's e-mail address may get sent to
				// the sending user.
				$mailFrom = $from;
				$replyTo = null;
			}
			
			$mailResult = UserMailer::send( $to, $mailFrom, $subject, $this->text, $replyTo );

			if( WikiError::isError( $mailResult ) ) {
				return $mailResult;
				
			} else {

				// if the user requested a copy of this mail, do this now,
				// unless they are emailing themselves, in which case one copy of the message is sufficient.
				if ($this->cc_me && $to != $from) {
					$cc_subject = wfMsg('emailccsubject', $this->target->getName(), $subject);
					if( wfRunHooks( 'EmailUser', array( &$from, &$from, &$cc_subject, &$this->text ) ) ) {
						$ccResult = UserMailer::send( $from, $from, $cc_subject, $this->text );
						if( WikiError::isError( $ccResult ) ) {
							// At this stage, the user's CC mail has failed, but their
							// original mail has succeeded. It's unlikely, but still, what to do?
							// We can either show them an error, or we can say everything was fine,
							// or we can say we sort of failed AND sort of succeeded. Of these options,
							// simply saying there was an error is probably best.
							return $ccResult;
						}
					}
				}

				wfRunHooks( 'EmailUserComplete', array( $to, $from, $subject, $this->text ) );
				return;
			}
		}
	}

	function showSuccess( &$user = null ) {
		global $wgOut;
		
		if ( is_null($user) )
			$user = $this->target;

		$wgOut->setPagetitle( wfMsg( "emailsent" ) );
		$wgOut->addHTML( wfMsg( "emailsenttext" ) );

		$wgOut->returnToMain( false, $user->getUserPage() );
	}
	
	function getTarget() {
		return $this->target;
	}
	
	static function validateEmailTarget ( $target ) {
		global $wgEnableEmail, $wgEnableUserEmail;

		if( !( $wgEnableEmail && $wgEnableUserEmail ) ) 
			return array( "nosuchspecialpage", "nospecialpagetext" );
		
		if ( "" == $target ) {
			wfDebug( "Target is empty.\n" );
			return array( "notargettitle", "notargettext" );
		}
	
		$nt = Title::newFromURL( $target );
		if ( is_null( $nt ) ) {
			wfDebug( "Target is invalid title.\n" );
			return array( "notargettitle", "notargettext" );
		}
	
		$nu = User::newFromName( $nt->getText() );
		if( is_null( $nu ) || !$nu->canReceiveEmail() ) {
			wfDebug( "Target is invalid user or can't receive.\n" );
			return array( "noemailtitle", "noemailtext" );
		}
		
		return $nu;
	}
	static function getPermissionsError ( $user, $editToken ) {
		if( !$user->canSendEmail() ) {
			wfDebug( "User can't send.\n" );
			return array( "mailnologin", "mailnologintext" );
		}
		
		if( $user->isBlockedFromEmailuser() ) {
			wfDebug( "User is blocked from sending e-mail.\n" );
			return array( "blockedemailuser", "" );
		}
		
		if( $user->pingLimiter( 'emailuser' ) ) {
			wfDebug( "Ping limiter triggered.\n" );	
			return array( 'actionthrottledtext', '' );
		}
		
		if( !$user->matchEditToken( $editToken ) ) {
			wfDebug( "Matching edit token failed.\n" );
			return array( 'sessionfailure', '' );
		}
		
		return;
	}
	
	static function newFromURL( $target, $text, $subject, $cc_me )
	{
		$nt = Title::newFromURL( $target );
		$nu = User::newFromName( $nt->getText() );
		return new EmailUserForm( $nu, $text, $subject, $cc_me );
	}
}
