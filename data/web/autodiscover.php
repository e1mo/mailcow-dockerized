<?php
require_once 'inc/vars.inc.php';
require_once 'inc/functions.inc.php';
if(file_exists('inc/vars.local.inc.php')) {
  include_once 'inc/vars.local.inc.php';
}

error_reporting(0);

$data = trim(file_get_contents("php://input"));

// Desktop client needs IMAP, unless it's Outlook 2013 or higher on Windows
if (strpos($data, 'autodiscover/outlook/responseschema')) { // desktop client
  $autodiscover_config['autodiscoverType'] = 'imap';
  if ($autodiscover_config['useEASforOutlook'] == 'yes' &&
  // Office for macOS does not support EAS
  strpos($_SERVER['HTTP_USER_AGENT'], 'Mac') === false &&
  // Outlook 2013 (version 15) or higher
  preg_match('/(Outlook|Office).+1[5-9]\./', $_SERVER['HTTP_USER_AGENT'])) {
    $autodiscover_config['autodiscoverType'] = 'activesync';
  }
}

$dsn = $database_type . ":host=" . $database_host . ";dbname=" . $database_name;
$opt = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];
$pdo = new PDO($dsn, $database_user, $database_pass, $opt);
$login_user = strtolower(trim($_SERVER['PHP_AUTH_USER']));
$login_role = check_login($login_user, $_SERVER['PHP_AUTH_PW']);

if (!isset($_SERVER['PHP_AUTH_USER']) OR $login_role !== "user") {
  header('WWW-Authenticate: Basic realm=""');
  header('HTTP/1.0 401 Unauthorized');
  exit(0);
}
else {
  if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
    if ($login_role === "user") {
      header("Content-Type: application/xml");
      echo '<?xml version="1.0" encoding="utf-8" ?>' . PHP_EOL;
?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
<?php
      if(!$data) {
        list($usec, $sec) = explode(' ', microtime());
?>
  <Response>
    <Error Time="<?=date('H:i:s', $sec) . substr($usec, 0, strlen($usec) - 2);?>" Id="2477272013">
      <ErrorCode>600</ErrorCode>
      <Message>Invalid Request</Message>
      <DebugData />
    </Error>
  </Response>
</Autodiscover>
<?php
        exit(0);
      }
      $discover = new SimpleXMLElement($data);
      $email = $discover->Request->EMailAddress;

      if ($autodiscover_config['autodiscoverType'] == 'imap') {
?>
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
    <User>
      <DisplayName><?=$displayname;?></DisplayName>
    </User>
    <Account>
      <AccountType>email</AccountType>
      <Action>settings</Action>
      <Protocol>
        <Type>IMAP</Type>
        <Server><?=$autodiscover_config['imap']['server'];?></Server>
        <Port><?=$autodiscover_config['imap']['port'];?></Port>
        <DomainRequired>off</DomainRequired>
        <LoginName><?=$email;?></LoginName>
        <SPA>off</SPA>
        <SSL><?=$autodiscover_config['imap']['ssl'];?></SSL>
        <AuthRequired>on</AuthRequired>
      </Protocol>
      <Protocol>
        <Type>SMTP</Type>
        <Server><?=$autodiscover_config['smtp']['server'];?></Server>
        <Port><?=$autodiscover_config['smtp']['port'];?></Port>
        <DomainRequired>off</DomainRequired>
        <LoginName><?=$email;?></LoginName>
        <SPA>off</SPA>
        <SSL><?=$autodiscover_config['smtp']['ssl'];?></SSL>
        <AuthRequired>on</AuthRequired>
        <UsePOPAuth>on</UsePOPAuth>
        <SMTPLast>off</SMTPLast>
      </Protocol>
      <Protocol>
        <Type>CalDAV</Type>
        <Server>https://<?=$mailcow_hostname;?>/SOGo/dav/<?=$email;?>/Calendar</Server>
        <DomainRequired>off</DomainRequired>
        <LoginName><?=$email;?></LoginName>
      </Protocol>
      <Protocol>
        <Type>CardDAV</Type>
        <Server>https://<?=$mailcow_hostname;?>/SOGo/dav/<?=$email;?>/Contacts</Server>
        <DomainRequired>off</DomainRequired>
        <LoginName><?=$email;?></LoginName>
      </Protocol>
    </Account>
  </Response>
<?php
      }
      else if ($autodiscover_config['autodiscoverType'] == 'activesync') {
        $username = trim($email);
        try {
          $stmt = $pdo->prepare("SELECT `name` FROM `mailbox` WHERE `username`= :username");
          $stmt->execute(array(':username' => $username));
          $MailboxData = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        catch(PDOException $e) {
          die("Failed to determine name from SQL");
        }
        if (!empty($MailboxData['name'])) {
          $displayname = utf8_encode($MailboxData['name']);
        }
        else {
          $displayname = $email;
        }
?>
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006">
    <Culture>en:en</Culture>
    <User>
      <DisplayName><?=$displayname;?></DisplayName>
      <EMailAddress><?=$email;?></EMailAddress>
    </User>
    <Action>
      <Settings>
        <Server>
        <Type>MobileSync</Type>
        <Url><?=$autodiscover_config['activesync']['url'];?></Url>
        <Name><?=$autodiscover_config['activesync']['url'];?></Name>
        </Server>
      </Settings>
    </Action>
  </Response>
<?php
      }
?>
</Autodiscover>
<?php
    }
  }
}
?>
