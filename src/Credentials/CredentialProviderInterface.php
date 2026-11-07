<?php
declare(strict_types=1);
namespace MyMessenger\Credentials;

interface CredentialProviderInterface {
	public function getCredentials(string $platform): CredentialBag;
}
