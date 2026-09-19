-- テスト用のデータベース。開発用の taskboard とは分離し、
-- テストが開発中のデータを壊さないようにする。
--
-- このディレクトリは postgres イメージの /docker-entrypoint-initdb.d に
-- マウントされており、データボリュームが空のときだけ実行される。
CREATE DATABASE taskboard_test OWNER app;
