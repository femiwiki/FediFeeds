-- The fedifeeds_actor table of sql/tables.json, for a wiki that has fedifeeds_reaction already
CREATE TABLE /*_*/fedifeeds_actor (
  fa_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  fa_actor VARBINARY(255) NOT NULL,
  fa_handle VARBINARY(255) DEFAULT '' NOT NULL,
  fa_name VARBINARY(255) DEFAULT '' NOT NULL,
  fa_url BLOB NOT NULL,
  fa_discoverable TINYINT DEFAULT 0 NOT NULL,
  fa_checked BINARY(14) NOT NULL,
  UNIQUE INDEX fa_actor (fa_actor),
  PRIMARY KEY(fa_id)
) /*$wgDBTableOptions*/;
