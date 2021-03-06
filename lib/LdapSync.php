<?php

namespace MonCompte;

class LdapSync {
	private static $logger;

	private static $conf;
	private static $ldapHandle;
	private static $isDisabled;

	private static function loadConfiguration() {
		$strCfg = file_get_contents(__DIR__.'/../config/local_ldap.json');
		self::$conf = json_decode($strCfg, true);
		self::$isDisabled = self::$conf['disabled'] === true;
		return self::$conf;
	}

	/**
	 * Returns ldap handle if successful, false otherwise.
	 */
	private static function openLdapConnection($host, $port, $userdn, $password) {
		$handle_ldap = ldap_connect($host, $port);

		ldap_set_option($handle_ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($handle_ldap, LDAP_OPT_REFERRALS, 0);

		if (!ldap_bind($handle_ldap, $userdn, $password))
			$handle_ldap = false;

		self::$ldapHandle = $handle_ldap;

		return $handle_ldap;
	}

	private static function initialize() {
		if (!self::$conf) {
			self::$logger = Logger::getLogger('LdapSync');
			self::loadConfiguration();

			if (!self::$isDisabled)
				self::openLdapConnection(self::$conf['host'],self::$conf['port'],self::$conf['userdn'],self::$conf['password']);
		}

		return self::$ldapHandle;
	}

	public static function migrer_vers_LDAP($leMembre) {
		$handle_ldap = self::initialize();

		if (self::$isDisabled) {
			self::$logger->info("Ldap is disabled, doing nothing.");
			return false;
		}

		$membrePath = "cn=".$leMembre["numero_membre"].", ".self::$conf['basedn'];
		$membreExists = @ldap_search($handle_ldap, $membrePath, "objectclass=*", ["cn"]);

		if(!$membreExists) {
			self::$logger->info('Adding membre to ldap repo: '.$leMembre["numero_membre"]);

			$membre_ldap = [
				"cn"			=> $leMembre["numero_membre"],
				"description"	=> ["admissible"],
				"displayname"	=> $leMembre["numero_membre"],
				"sn"			=> $leMembre["nom"],
				"homedirectory"	=> "/home/users/".$leMembre["numero_membre"],
				"objectclass"	=> ["person", "posixAccount", "inetOrgPerson", "top", "organizationalPerson"],
				"gidNumber"		=> 10000,
				"givenName"		=> $leMembre["prenom"],
				"uid"			=> $leMembre["numero_membre"],
				"userPassword"	=> Guid::generate(),
				"uidNumber"		=> $leMembre["numero_membre"],
				"mail"			=> $leMembre["email"]
			];

			if(empty($leMembre["email"]))
				$email = self::$conf['defaultEmail'];
			else
				$email = $leMembre["email"];

			$reussi = @ldap_add($handle_ldap, $membrePath, $membre_ldap);

			if (!$reussi)
				return ldap_error($handle_ldap);

		} else {
			self::$logger->debug('Membre already exist in ldap repo: '.$leMembre["numero_membre"]);
		}

		return false;
	}

	static public function maj_statut_cotisant($numero_membre, $cotisationExpirationTimestamp) {
		$handle_ldap = self::initialize();

		if (self::$isDisabled) {
			self::$logger->info("Ldap is disabled, doing nothing.");
			return false;
		}

		$membreExists = @ldap_search($handle_ldap, "cn={$numero_membre}, ".self::$conf['basedn'], "objectclass=*", array("cn", "description", "mail"));

		if($membreExists) {
			$personnes = ldap_get_entries($handle_ldap, $membreExists);
			$personne = $personnes[0];
			$dn = $personne["dn"];

			if(@is_array($personne["description"]))
				$groupes = array_flip($personne["description"]);
			else
				$groupes = Null;

			$currentTime = time();
			$est_membre = ($currentTime < $cotisationExpirationTimestamp);
			//self::$logger->debug(">>>> #{$numero_membre} : {$currentTime} < {$cotisationExpirationTimestamp}");

			if (isset($groupes["membre"]) && !$est_membre) {
				self::$logger->debug("Removing membership for #{$numero_membre}.");
				$e = array();
				$e["description"][] = "membre";
				ldap_mod_del($handle_ldap, $dn, $e);
			} elseif (!isset($groupes["membre"]) && $est_membre) {
				self::$logger->debug("Adding membership for #{$numero_membre}.");
				$e = array();
				$e["description"][] = "membre";
				ldap_mod_add($handle_ldap, $dn, $e);
			} else {
				self::$logger->debug("No status change for #{$numero_membre}.");
			}

			$err = ldap_error($handle_ldap);
			if($err != "Success")
				return "Ldap error while updating membre #{$numero_membre} status: {$err}";
		} else {
			return "Membre not found in ldap repo: #{$numero_membre}";
		}
	}

	public static function updateOrCreateProfile($numero_membre, $data) {
		$handle_ldap = self::initialize();

		if (self::$isDisabled) {
			self::$logger->info("Ldap is disabled, doing nothing.");
			return false;
		}

		$membreExists = @ldap_search($handle_ldap, "cn={$numero_membre}, ".self::$conf['basedn'], "objectclass=*", array("cn", "description", "mail"));

		if($membreExists) {
			$personnes = ldap_get_entries($handle_ldap, $membreExists);
			$personne = $personnes[0];
			$dn = $personne["dn"];

			//self::$logger->debug(print_r($personne, true));

			$newEmail = self::$conf['defaultEmail'];

			if (isset($data['email']) && $data['email'])
				$newEmail = $data['email'];

			$hasLdapEmail = @is_array($personne["mail"]);

			$ldapData = [
				'mail' => [$newEmail]
			];

			if($hasLdapEmail) {
				self::$logger->info("Replacing ldap email for #{$numero_membre}: {$newEmail}");
				@ldap_mod_replace($handle_ldap, $dn, $ldapData);
			} else {
				self::$logger->info("Adding ldap email for #{$numero_membre}: {$newEmail}");
				@ldap_mod_add($handle_ldap, $dn, $ldapData);
			}

			self::$logger->info("Replacing ldap first and last names for #{$numero_membre}.");
			@ldap_mod_replace($handle_ldap, $dn, [
				"sn"		=> $data["nom"],
				"givenName"	=> $data["prenom"],
			]);

			$err = ldap_error($handle_ldap);
			if($err != "Success")
				return $err;
		} else {
			return self::migrer_vers_LDAP($data);
		}
	}}
