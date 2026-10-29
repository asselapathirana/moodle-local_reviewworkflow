<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
function xmldb_local_reviewworkflow_upgrade($oldversion): bool {
    global $DB;
    if ($oldversion < 2026102802) {
        upgrade_plugin_savepoint(true, 2026102802, 'local', 'reviewworkflow');
    }
    if ($oldversion < 2026102803) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_reviewworkflow_imp');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('mode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('idtype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourcehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('added', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('removed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('released', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('tokenhash', XMLDB_INDEX_UNIQUE, ['tokenhash']);
        $table->add_index('cmid', XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }
        $table = new xmldb_table('local_reviewworkflow_chg');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('importid', XMLDB_INDEX_NOTUNIQUE, ['importid']);
        $table->add_index('studentid', XMLDB_INDEX_NOTUNIQUE, ['studentid']);
        $table->add_index('reviewerid', XMLDB_INDEX_NOTUNIQUE, ['reviewerid']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }
        upgrade_plugin_savepoint(true, 2026102803, 'local', 'reviewworkflow');
    }
    if ($oldversion < 2026102804) {
        upgrade_plugin_savepoint(true, 2026102804, 'local', 'reviewworkflow');
    }
    if ($oldversion < 2026102900) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_reviewworkflow_cfg');
        foreach (['requestsubject', 'requestbody', 'completesubject', 'completebody'] as $name) {
            $field = new xmldb_field($name, XMLDB_TYPE_TEXT, null, null, null, null, null);
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        }
        upgrade_plugin_savepoint(true, 2026102900, 'local', 'reviewworkflow');
    }
    if ($oldversion < 2026102901) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_reviewworkflow_req');
        $field = new xmldb_field('evidencecaptured', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $xml = new xmldb_file(__DIR__ . '/install.xml');
        $xml->loadXMLStructure();
        foreach (['local_reviewworkflow_exp', 'local_reviewworkflow_exi'] as $name) {
            $table = $xml->getStructure()->getTable($name);
            if (!$dbman->table_exists($table)) { $dbman->create_table($table); }
        }
        upgrade_plugin_savepoint(true, 2026102901, 'local', 'reviewworkflow');
    }
    return true;
}
