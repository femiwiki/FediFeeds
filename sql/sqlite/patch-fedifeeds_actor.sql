-- The fedifeeds_actor table of sql/tables.json, for a wiki that has fedifeeds_reaction already
CREATE TABLE /*_*/fedifeeds_actor (
  fa_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  fa_actor BLOB NOT NULL, fa_handle BLOB DEFAULT '' NOT NULL,
  fa_name BLOB DEFAULT '' NOT NULL, fa_url BLOB NOT NULL,
  fa_discoverable SMALLINT DEFAULT 0 NOT NULL,
  fa_checked BLOB NOT NULL
);

CREATE UNIQUE INDEX fa_actor ON /*_*/fedifeeds_actor (fa_actor);
