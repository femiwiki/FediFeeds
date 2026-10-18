<?php

namespace MediaWiki\Extension\FediFeeds\Signatures;

/**
 * The wiki's own key pair, from its private key; LocalKeyStore keeps it
 */
class LocalKey {

	public function __construct(
		private readonly string $privateKeyPem,
	) {
	}

	/**
	 * The public key every actor publishes, or null if the private key does not load.
	 */
	public function getPublicKeyPem(): ?string {
		$key = openssl_pkey_get_private( $this->privateKeyPem );
		return $key ? ( openssl_pkey_get_details( $key )['key'] ?? null ) : null;
	}

	/**
	 * @param string $method
	 * @param string $target
	 * @param array<string,string> $headers Keyed by lower-case name
	 * @param string $keyId
	 * @return string The Signature header
	 */
	public function sign( string $method, string $target, array $headers, string $keyId ): string {
		return HttpSignature::sign( $method, $target, $headers, $keyId, $this->privateKeyPem );
	}
}
