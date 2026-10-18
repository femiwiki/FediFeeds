<?php

namespace MediaWiki\Extension\FediFeeds\Signatures;

use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Keeps the wiki's key pair in the database, generating it the first time it is needed
 */
class LocalKeyStore {

	private const NAME = 'main';

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	public function getKey(): LocalKey {
		$pem = $this->load( $this->dbProvider->getReplicaDatabase() )
			?? $this->load( $this->dbProvider->getPrimaryDatabase() )
			?? $this->generate();
		return new LocalKey( $pem );
	}

	private function load( IReadableDatabase $db ): ?string {
		$pem = $db->newSelectQueryBuilder()
			->select( 'fk_private_key' )
			->from( 'fedifeeds_key' )
			->where( [ 'fk_name' => self::NAME ] )
			->caller( __METHOD__ )->fetchField();
		return is_string( $pem ) && $pem !== '' ? $pem : null;
	}

	/**
	 * Generates a key and stores it unless another request stored one first, then returns
	 * whichever is stored.
	 */
	private function generate(): string {
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if ( !$key || !openssl_pkey_export( $key, $pem ) ) {
			throw new RuntimeException( 'Could not generate a key pair: ' . openssl_error_string() );
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_key' )
			->ignore()
			->row( [ 'fk_name' => self::NAME, 'fk_private_key' => $pem, 'fk_timestamp' => $dbw->timestamp() ] )
			->caller( __METHOD__ )->execute();
		return $this->load( $dbw ) ?? $pem;
	}
}
