<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Get all bank accounts
 *
 * @param $data
 * @return mixed
 */
function erp_acct_get_banks ( $show_balance = false, $with_cash = false, $no_bank = false ) {
    global $wpdb;

    $ledgers   = $wpdb->prefix . 'erp_acct_ledgers';
    $show_all  = false;
    $cash_only = false;
    $bank_only = false;

    $chart_id    = 7;
    $cash_ledger = '';
    $where       = '';
    if ( $with_cash && ! $no_bank ) {
        $where       = " WHERE chart_id = {$chart_id}";
        $cash_ledger = " OR slug = 'cash' ";
        $show_all    = true;
    }

    if ( $with_cash && $no_bank ) {
        $where       = " WHERE";
        $cash_ledger = " slug = 'cash' ";
        $cash_only   = true;
    }

    if ( ! $with_cash && ! $no_bank ) {
        $where       = " WHERE chart_id = {$chart_id}";
        $cash_ledger = "";
        $bank_only   = true;
    }

    if ( ! $show_balance ) {
        $query   = "SELECT * FROM $ledgers" . $where . $cash_ledger;
        $results = $wpdb->get_results( $query, ARRAY_A );
        return $results;
    }

    $sub_query      = "SELECT id FROM $ledgers" . $where . $cash_ledger;
    $ledger_details = $wpdb->prefix . 'erp_acct_ledger_details';
    $query          = "Select l.id, ld.ledger_id, l.name, SUM(ld.debit - ld.credit) as balance
              From $ledger_details as ld
              LEFT JOIN $ledgers as l ON l.id = ld.ledger_id
              Where ld.ledger_id IN ($sub_query)
              Group BY ld.ledger_id";

    $accts = $wpdb->get_results( $query, ARRAY_A );

    for ( $i = 0; $i < count( $accts ); $i++ ) {
        if ( 1 == $accts[ $i ]['ledger_id'] ) {
            $accts[ $i ]['balance'] = (float) get_ledger_balance_with_opening_balance( 1 );
        }
    }

    if ( $cash_only && ! empty( $accts ) ) {
        return $accts;
    }

    if ( empty( $accts ) && ( $cash_only || $show_all ) ) {
        $acct['id']      = 1;
        $acct['name']    = 'Cash';
        $acct['balance'] = 0;

        $accts[] = $acct;
    }

    $banks = erp_acct_get_ledgers_by_chart_id( 7 );

    if ( $bank_only && empty( $banks ) ) {
        return new WP_Error( 'rest_empty_accounts', __( 'Bank accounts are empty.' ), [ 'status' => 204 ] );
    }

    $results = array_merge( $accts, $banks );

    $uniq_accts = array();

    foreach ( $results as $index => $result ) {
        if ( ! empty( $uniq_accts ) && in_array( $result['id'], $uniq_accts ) ) {
            unset( $results[ $index ] );
            continue;
        }
        $uniq_accts[] = $result['id'];
    }

    return $results;
}

/**
 * Get all accounts to show in dashboard
 *
 * @param $data
 * @return mixed
 */
function erp_acct_get_dashboard_banks () {
    $results   = [];
    $results[] = [
        'name'    => 'Cash',
        'balance' => get_ledger_balance_with_opening_balance( 1 ),
    ];

    $args['start_date'] = $args['end_date'] = date( "Y-m-d" );
    $results[]          = [
        'name'       => 'Cash at Bank',
        'balance'    => erp_acct_cash_at_bank( $args, 'balance' ),
        'additional' => erp_acct_dashboard_balance_bank_balance( 'balance' )
    ];

    return $results;
}

/**
 * Dashboard account helper
 *
 * @param object $additionals
 *
 * @return float
 */
function erp_acct_dashboard_balance_cash_at_bank ( $additionals ) {
    $balance = 0;

    foreach ( $additionals as $additional ) {
        $balance += (float) $additional['balance'];
    }

    return $balance;
}

/**
 * Dashboard account helper
 *
 * @param string $type
 *
 * @return mixed
 */
function erp_acct_dashboard_balance_bank_balance ( $type ) {
    global $wpdb;

    if ( 'loan' === $type ) {
        $having = "HAVING balance < 0";
    } elseif ( 'balance' === $type ) {
        $having = "HAVING balance >= 0";
    }

    $sql = "SELECT ledger.id, ledger.name, SUM( debit - credit ) AS balance
        FROM {$wpdb->prefix}erp_acct_ledgers AS ledger
        LEFT JOIN {$wpdb->prefix}erp_acct_ledger_details AS ledger_detail ON ledger.id = ledger_detail.ledger_id
        WHERE ledger.chart_id = 7 GROUP BY ledger.id {$having}";

    return $wpdb->get_results( $sql, ARRAY_A );
}

/**
 * Get a single bank account
 *
 * @param $bank_no
 * @return mixed
 */
function erp_acct_get_bank ( $bank_no ) {
    global $wpdb;

    $row = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . "wp_erp_acct_cash_at_banks WHERE ledger_id = {$bank_no}", ARRAY_A );

    return $row;
}

/**
 * Insert a bank account
 *
 * @param $data
 * @param $bank_id
 * @return int
 */
function erp_acct_insert_bank ( $data ) {
    global $wpdb;

    $bank_data = erp_acct_get_formatted_bank_data( $data );

    try {
        $wpdb->query( 'START TRANSACTION' );

        $wpdb->insert( $wpdb->prefix . 'erp_acct_cash_at_banks', array(
            'ledger_id' => $bank_data['ledger_id']
        ) );

        $wpdb->query( 'COMMIT' );

    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_error( 'bank-account-exception', $e->getMessage() );
    }
    return $bank_data['ledger_id'];

}


/**
 * Delete a bank account
 *
 * @param $id
 * @return int
 */
function erp_acct_delete_bank ( $id ) {
    global $wpdb;

    try {
        $wpdb->query( 'START TRANSACTION' );
        $wpdb->delete( $wpdb->prefix . 'erp_acct_cash_at_banks', array( 'ledger_id' => $id ) );
        $wpdb->query( 'COMMIT' );

    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_error( 'bank-account-exception', $e->getMessage() );
    }

    return $id;
}


/**
 * Get formatted bank data
 *
 * @param $data
 * @param $voucher_no
 *
 * @return mixed
 */
function erp_acct_get_formatted_bank_data ( $data ) {

    $bank_data['ledger_id'] = ! empty( $bank_data['ledger_id'] ) ? $bank_data['ledger_id'] : 0;

    return $bank_data;
}

/**
 * Get balance of a single account
 *
 * @param $ledger_id
 *
 */

function erp_acct_get_single_account_balance ( $ledger_id ) {
    global $wpdb;

    $result = $wpdb->get_row( "SELECT ledger_id, SUM(credit) - SUM(debit) AS 'balance' FROM " . $wpdb->prefix . "erp_acct_ledger_details WHERE ledger_id = {$ledger_id}", ARRAY_A );

    return $result;
}

/**
 * @param $ledger_id
 *
 * @return array
 */
function erp_acct_get_account_debit_credit ( $ledger_id ) {
    global $wpdb;
    $dr_cr = [];

    $dr_cr['debit']  = $wpdb->get_var( "SELECT SUM(debit) FROM " . $wpdb->prefix . "erp_acct_ledger_details WHERE ledger_id = {$ledger_id}" );
    $dr_cr['credit'] = $wpdb->get_var( "SELECT SUM(credit) FROM " . $wpdb->prefix . "erp_acct_ledger_details WHERE ledger_id = {$ledger_id}" );

    return $dr_cr;

}

/**
 * Perform transfer amount between two account
 *
 * @param $item
 */
function erp_acct_perform_transfer ( $item ) {
    global $wpdb;
    $created_by = get_current_user_id();
    $created_at = date( "Y-m-d" );
    $updated_at = date( "Y-m-d" );
    $updated_by = $created_by;

    try {
        $wpdb->query( 'START TRANSACTION' );

        $wpdb->insert( $wpdb->prefix . 'erp_acct_voucher_no', array(
            'type'       => 'transfer_voucher',
            'created_at' => $created_at,
            'created_by' => $created_by,
            'updated_at' => $updated_at,
            'updated_by' => $updated_by,
        ) );

        $voucher_no = $wpdb->insert_id;

        // Inset transfer amount in ledger_details
        $wpdb->insert( $wpdb->prefix . 'erp_acct_ledger_details', array(
            'ledger_id'   => $item['from_account_id'],
            'trn_no'      => $voucher_no,
            'particulars' => $item['particulars'],
            'debit'       => 0,
            'credit'      => $item['amount'],
            'trn_date'    => $item['date'],
            'created_at'  => $created_at,
            'created_by'  => $created_by,
            'updated_at'  => $updated_at,
            'updated_by'  => $updated_by,
        ) );

        $wpdb->insert( $wpdb->prefix . 'erp_acct_ledger_details', array(
            'ledger_id'   => $item['to_account_id'],
            'trn_no'      => $voucher_no,
            'particulars' => $item['particulars'],
            'debit'       => $item['amount'],
            'credit'      => 0,
            'trn_date'    => $item['date'],
            'created_at'  => $created_at,
            'created_by'  => $created_by,
            'updated_at'  => $updated_at,
            'updated_by'  => $updated_by,
        ) );

        $wpdb->insert( $wpdb->prefix . 'erp_acct_transfer_voucher', array(
            'voucher_no'  => $voucher_no,
            'amount'      => $item['amount'],
            'ac_from'     => $item['from_account_id'],
            'ac_to'       => $item['to_account_id'],
            'particulars' => $item['particulars'],
            'trn_date'    => $item['date'],
            'created_at'  => $created_at,
            'created_by'  => $created_by,
            'updated_at'  => $updated_at,
            'updated_by'  => $updated_by,
        ) );

        erp_acct_sync_dashboard_accounts();

        $wpdb->query( 'COMMIT' );

    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_error( 'transfer-exception', $e->getMessage() );
    }

}

/**
 * Sync dashboard account on transfer
 */
function erp_acct_sync_dashboard_accounts () {
    global $wpdb;

    $accounts = erp_acct_get_banks( true, true, false );

    foreach ( $accounts as $account ) {
        $wpdb->update( $wpdb->prefix . 'erp_acct_cash_at_banks', array(
            'balance' => $account['balance'],
        ), array(
            'ledger_id' => $account['ledger_id']
        ) );
    }

}

/**
 * Get transferrable accounts
 */
function erp_acct_get_transfer_accounts ( $show_balance = false ) {
    /*
    global $wpdb;

    $ledger_map = \WeDevs\ERP\Accounting\Includes\Classes\Ledger_Map::getInstance();
    $cash_ledger = $ledger_map->get_ledger_details_by_slug( 'cash' );

    $ledgers = $wpdb->prefix.'erp_acct_ledgers';
    $chart_id = $cash_ledger->chart_id;

    if ( !$show_balance ) {
        $query = $wpdb->prepare( "Select * FROM $ledgers WHERE chart_id = %d", $chart_id );
        $results = $wpdb->get_results( $query, ARRAY_A );
        return $results;
    }

    $sub_query = $wpdb->prepare( "Select id FROM $ledgers WHERE chart_id = %d", $chart_id );
    $cash_ledger = $wpdb->prefix.'erp_acct_ledger_details';
    $query = "Select ld.ledger_id, l.name, SUM(ld.debit - ld.credit) as balance
              From $cash_ledger as ld
              LEFT JOIN $ledgers as l ON l.id = ld.ledger_id
              Where ld.ledger_id IN ($sub_query)
              Group BY ld.ledger_id";
    */

    $results = erp_acct_get_banks( true, true, false );

    return $results;
}

/**
 * Get created Transfer voucher list
 *
 * @param array $args
 *
 * @return array
 */
function erp_acct_get_transfer_vouchers ( $args = [] ) {
    global $wpdb;

    $defaults = [
        'number'   => 20,
        'offset'   => 0,
        'order_by' => 'id',
        'order'    => 'DESC',
        'count'    => false,
        's'        => '',
    ];

    $args = wp_parse_args( $args, $defaults );

    $limit = '';

    if ( $args['number'] != '-1' ) {
        $limit = "LIMIT {$args['number']} OFFSET {$args['offset']}";
    }

    $table = $wpdb->prefix . 'erp_acct_transfer_voucher';
    $query = "Select * From $table ORDER BY {$args['order_by']} {$args['order']} {$limit}";

    $result = $wpdb->get_results( $query, ARRAY_A );

    return $result;
}

/**
 * Get single voucher
 *
 * @param integer $id Voucher id
 * @return object     Single voucher
 */
function erp_acct_get_single_voucher ( $id ) {
    global $wpdb;

    if ( ! $id ) {
        return;
    }

    $table = $wpdb->prefix . 'erp_acct_transfer_voucher';
    $query = "Select * From $table WHERE id = {$id}";

    $result = $wpdb->get_row( $query );

    return $result;
}

/**
 * Get balance by Ledger ID
 *
 * @param $id array
 *
 * @return array
 */
function erp_acct_get_balance_by_ledger ( $id ) {
    if ( is_array( $id ) ) {
        $id = "'" . implode( "','", $id ) . "'";
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'erp_acct_ledger_details';
    $query      = "Select ld.ledger_id,SUM(ld.debit - ld.credit) as balance From $table_name as ld Where ld.ledger_id IN ($id) Group BY ld.ledger_id ";
    $result     = $wpdb->get_results( $query, ARRAY_A );

    return $result;
}
