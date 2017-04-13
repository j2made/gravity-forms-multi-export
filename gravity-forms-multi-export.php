<?php
/**
* Gravity Wiz // Gravity Forms // Multi-form Entry Exporter
*
* Allows you create a custom entry export containing fields from multiple forms. The custom export
* is then displayed in the "Form" drop down menu in a "Multi-form Export" option group.
*
* @version   1.0
* @author    David Smith <david@gravitywiz.com>
* @license   GPL-2.0+
* @link      http://gravitywiz.com/...
* @copyright 2014 Gravity Wiz
*
* Plugin Name: Gravity Forms Multi-form Entry Exporter
* Plugin URI:  http://gravitywiz.com/
* Version:     1.0
* Description: Allows you create a custom entry export containing fields from multiple forms.
* Author:      David Smith <david@gravitywiz.com>
* Author URI:  http://gravitywiz.com/
* License:     GPL2
*/
class GW_Multi_Form_Entry_Exporter_Interface {

    private $exports = array();
    private static $instance = null;

    public static function get_instance() {

        if( self::$instance === null )
            self::$instance = new self;

        return self::$instance;
    }

    protected function __construct() {

        add_filter( 'init', array( $this, 'initialize' ), 9 );

    }

    public function initialize() {

        if( empty( $this->exports ) )
            return;

        add_action( 'admin_footer-forms_page_gf_export', array( $this, 'output_export_markup' ) );

        $this->maybe_export();

    }

    public function register_export( $export ) {

        $name = $export->_args['name'];
        if( array_key_exists( $name, $this->exports ) )
            return new WP_Error( 'name_already_registered', __( 'This export name has already been registered.' ) );

        $this->exports[$name] = $export;

        return true;
    }

    public function maybe_export() {

        if( ! rgpost( 'gwmfee_flag' ) )
            return;

        check_admin_referer( 'rg_start_export', 'rg_start_export_nonce' );

        $export = rgar( $this->exports, $_POST['export_form'] );
        if( ! $export )
            return;

        if( ! empty( $export->_args['form_ids'] ) ) {
            $export->_args['columns'] = $this->get_auto_columns( $export );
        }

        $filename = sanitize_title_with_dashes( $export->_args['name'] ) . '-' . gmdate( 'Y-m-d', GFCommon::get_local_timestamp( time() ) ) . '.csv';
        $charset = get_option( 'blog_charset' );

        header( 'Content-Description: File Transfer' );
        header( "Content-Disposition: attachment; filename={$filename}" );
        header( "Content-Type: text/plain; charset={$charset}", true);

        $buffer_length = ob_get_length();
        if( $buffer_length > 1 )
            ob_clean();

        $this->export( $export );

        die();
    }

    public function output_export_markup() {

        $export_names = $this->get_exports_arg( 'name' );

        ?>

        <script type="text/javascript">

            jQuery( document ).ready( function( $ ) {

                var exports           = <?php echo json_encode( $export_names ); ?>,
                    formSelect        = $( '#export_form' ),
                    placeholderOption = formSelect.find( 'option:first-child' ),
                    optionsString     = '';

                $.each( exports, function( i, exportName ) {
                    optionsString += '<option value="' + exportName + '">' + exportName + '</option>';
                } );

                var placeholderOptionHTML = placeholderOption[0].outerHTML;
                placeholderOption.remove();

                formSelect.html(
                    placeholderOptionHTML +
                    '<optgroup label="Multi-form Exports" id="gwmfee_group">' + optionsString + '</optgroup>' +
                    '<optgroup label="Forms">' + formSelect.html() + '</optgroup>'
                );

                formSelect.attr( 'onchange', 'if( ! gwmfeeSelectExport( this ) ) { ' + formSelect.attr( 'onchange' ) + ' };' );

            } );

            function gwmfeeSelectExport( selectElem ) {

                var optionElem        = jQuery( selectElem ).find( 'option:selected' ),
                    isMultiFormExport = optionElem.parents( 'optgroup#gwmfee_group' ).length > 0,
                    gwmfeeFlag        = jQuery( '#gwmfee_flag' ),
                    exportForm        = jQuery( '#gform_export' ),
                    shimCheckbox      = jQuery( '#gwmfee_shim' );

                if( ! isMultiFormExport ) {
                    gwmfeeFlag.remove();
                    shimCheckbox.remove();
                    return false;
                }

                // hide all inputs in case they are already open
                jQuery( '#export_field_container, #export_filter_container, #export_date_container, #export_submit_container' ).hide();

                // show date and submit inputs
                jQuery( '#export_date_container, #export_submit_container' ).show();

                if( gwmfeeFlag.length <= 0 ) {
                    exportForm.append( '<input type="hidden" value="1" name="gwmfee_flag" id="gwmfee_flag" />');
                    exportForm.append( '<input type="checkbox" value="1" checked="checked" class="gform_export_field" id="gwmfee_shim" style="display:none;" />' );
                }

                // initialize datepicker functionality for date range inputs
                jQuery( '#export_date_start, #export_date_end' ).datepicker( { dateFormat: 'yy-mm-dd', changeMonth: true, changeYear: true } );

                return true;
            }

        </script>

        <?php
    }

    public function export( $export ) {

        require_once( GFCommon::get_base_path() . '/export.php' );

        $start_date = empty( $_POST['export_date_start'] ) ? '' : GFExport::get_gmt_date( $_POST['export_date_start'] . ' 00:00:00' );
        $end_date   = empty( $_POST['export_date_end'] )   ? '' : GFExport::get_gmt_date( $_POST['export_date_end'] . ' 23:59:59' );

        $search_criteria['status'] = 'active';
        $search_criteria['field_filters'] = GFCommon::get_field_filters_from_post();

        if( ! empty( $start_date ) )
            $search_criteria['start_date'] = $start_date;

        if( ! empty( $end_date ) )
            $search_criteria['end_date'] = $end_date;

        $sorting = array(
            'key'       => 'date_created',
            'direction' => 'DESC',
            'type'      => 'info'
        );

        $form_ids = $this->get_export_form_ids( $export );
        $columns = $export->_args['columns'];
        $data = array();
        $entry_count = GFAPI::count_entries( $form_ids, $search_criteria );

        $page_size = 100;
        $offset = 0;

        // adding BOM marker for UTF-8
        $lines = chr( 239 ) . chr( 187 ) . chr( 191 );

        // set the separater
        $separator = apply_filters( 'gform_export_separator', ',', $form_ids[0] );

        // only applies to list fields, not currently supported
        $field_rows = array(); //self::get_field_row_count( $form, $fields, $entry_count);

        // headers
        $data[0] = array();
        foreach( $columns as $column_name => $fields ) {
            $data[0][] = $column_name;
        }

        // paging through results for memory issues
        while( $entry_count > 0 ) {

            $paging = array(
                'offset'    => $offset,
                'page_size' => $page_size
            );

            $entries = GFAPI::get_entries( $form_ids, $search_criteria, $sorting, $paging );
            $entries = apply_filters( 'gform_leads_before_export', $entries, array(), $paging );

            foreach( $entries as $entry ) {

                $row = array();

                foreach( $columns as $column_name => $column ) {

                    $form_id = $entry['form_id'];
                    $field_id = $this->get_column_field_id( $export, $column_name, $form_id );
                    $value = '';

                    $form = GFAPI::get_form( $form_id );
                    if( ! $form ) {
                        $row[$column_name] = $value;
                        continue;
                    }

                    switch( $field_id ) {

                        case 'date_created':
                            $entry_gmt_time = mysql2date( 'G', $entry['date_created'] );
                            $entry_local_time = GFCommon::get_local_timestamp( $entry_gmt_time );
                            $value = date_i18n( 'Y-m-d H:i:s', $entry_local_time, true );
                            break;

                        default:

                            $long_text = '';
                            if( strlen( rgar( $entry, $field_id ) ) >= ( GFORMS_MAX_FIELD_LENGTH - 10 ) )
                                $long_text = GFFormsModel::get_field_value_long( $entry, $field_id, $form );

                            $value = ! empty( $long_text ) ? $long_text : rgar( $entry, $field_id );
                            $field = GFFormsModel::get_field( $form, $field_id );
                            $input_type = GFFormsModel::get_input_type( $field );

                            if($input_type == "checkbox"){
                                $value = GFFormsModel::is_checkbox_checked( $field_id, GFCommon::get_label( $field, $field_id ), $entry, $form );
                                if($value === false)
                                    $value = "";
                            }
                            else if($input_type == "fileupload" && rgar($field,"multipleFiles") ){
                                $value = !empty($value) ? implode(" , ", json_decode($value, true)) : "";
                            }

                            $value = apply_filters( 'gform_export_field_value', $value, $form_id, $field_id, $entry );
                            break;

                    }

                    $row[$column_name] = $value;

                }

                $data[] = $row;

            }

            $offset += $page_size;
            $entry_count -= $page_size;

        }

        foreach( $data as &$row ) {
            $row = implode( $separator, array_map( array( $this, 'prepare_export_value' ), $row ) );
        }

        $output = implode( "\n", $data );

        if ( ! seems_utf8( $output ) )
            $lines = utf8_encode( $output );

        echo $output;

    }

    public function get_exports_arg( $arg ) {

        $args = array();

        foreach( $this->exports as $export ) {
            $args[] = rgar( $export->_args, $arg );
        }

        return array_filter( $args );
    }

    public function get_export_form_ids( $export ) {

        $form_ids = array();

        foreach( $export->_args['columns'] as $column ) {
            foreach( $column as $field_data ) {
                list( $form_id, $field_id ) = $field_data;
                $form_ids[] = $form_id;
            }
        }

        return array_unique( $form_ids );
    }

    public function get_column_field_id( $export, $column_name, $form_id ) {

        foreach( $export->_args['columns'] as $_column_name => $column ) {

            if( $_column_name != $column_name )
                continue;

            foreach( $column as $field ) {

                list( $_form_id, $field_id ) = $field;
                if( $_form_id == $form_id )
                    return $field_id;

            }
        }

        return false;
    }

    public function prepare_export_value( $value ) {

        $value = maybe_unserialize( $value );
        if( is_array( $value ) )
            $value = implode( '|', $value );

        $value = str_replace( '"', '""', $value );

        return "\"{$value}\"";
    }

    public function get_auto_columns( $export ) {

        $form_ids = $export->_args['form_ids'];
        $columns = array();

        foreach( $form_ids as $form_id ) {

            $form = GFAPI::get_form( $form_id );

            if( ! $form ) {
                continue;
            }

            $current_form_column_names = array();

            foreach( $form['fields'] as $field ) {

                // @todo: add support for multi-input fields

                // field's admin label is default column name, field label used if no admin label provided
                $column_name = rgar( $field, 'adminLabel' );

                if( ! $column_name ) {
                    $column_name = GFCommon::get_label( $field );
                }

                // if multiple fields on the form have the same name, skip them
                if( in_array( $column_name, $current_form_column_names ) ) {
                    continue;
                }

                $current_form_column_names[] = $column_name;

                if( ! isset( $columns[ $column_name ] ) ) {
                    $columns[ $column_name ] = array();
                }

                $columns[ $column_name ][] = array( $form_id, $field['id'] );

            }

        }

        return $columns;
    }




    public function enqueue_admin_scripts() {

        if( rgget( 'page' ) != 'gf_export' || rgget( 'view' ) != 'gw_multi_form_entry_exporter' )
            return;

        wp_enqueue_script( 'jquery-ui-datepicker' );

    }

    public function add_exporter_menu_item( $menu_items ) {

        $menu_items[] = array(
            'name' => 'gw_multi_form_entry_exporter',
            'label' => __( 'Multi Form Entry Exporter' )
        );

        return $menu_items;
    }

    public function display_exporter_page() {

        GFExport::page_header();
        ?>

        <style type="text/css">
            input,
            textarea {
                outline-style: none;
                font-family: sans-serif;
                font-size: inherit;
                padding: 3px 5px;
            }
        </style>

        <p class="textleft">
            <?php _e( 'You can register a multi form entry export by creating a new instance of the <strong>GW_Multi_Form_Entry_Exporter</strong> class.' ); ?>
        </p>

        <div class="hr-divider"></div>

        <form id="gw_multi_form_entry_exporter" method="post">

            <?php echo wp_nonce_field( 'gw_mfee_export', 'gw_mfee_export' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="gwmfee_export"><?php _e( 'Select an Export' ); ?></label>
                        <?php gform_tooltip( 'gwmfee_export' ); ?>
                    </th>
                    <td>
                        <select id="gwmfee_export" name="gwmfee_export" onchange="gwSelectExport( jQuery( this ).val() );">
                            <option value=""><?php _e( 'Select an export' ); ?></option>
                            <?php foreach( $this->exports as $export ): ?>
                                <option value="<?php echo $export->_args['name']; ?>"><?php echo $export->_args['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="gwmfee_export_date_container" valign="top" style="display: none;">
                    <th scope="row">
                        <label for="gwmfee_export_date_range"><?php _e( 'Select Date Range', 'gravityforms' ); ?></label>
                        <?php gform_tooltip( 'gwmfee_export_date_range' ) ?>
                    </th>
                    <td>
                        <div>

                            <span style="width:150px; float:left;">
                                <input type="text" id="gwmfee_export_date_start" name="gwmfee_export_date_start" style="width:90%" />
                                <label for="gwmfee_export_date_start" style="display:block;font-size:0.9em;">
                                    <strong><?php _e( 'Start', 'gravityforms' ); ?></strong>
                                </label>
                            </span>

                            <span style="width:150px; float:left;">
                                <input type="text" id="gwmfee_export_date_end" name="gwmfee_export_date_end" style="width:90%"/>
                                <label for="gwmfee_export_date_end" style="display:block;font-size:0.9em;">
                                    <strong><?php _e( 'End', 'gravityforms' ); ?></strong>
                                </label>
                            </span>

                            <p class="description" style="clear:both;"><?php _e( 'Date Range is optional, if no date range is selected all entries will be exported.', 'gravityforms' ); ?></p>

                        </div>
                    </td>
                </tr>
            </table>

            <input type="submit" name="gwmfee_export_entries" value="<?php _e( 'Download Export File', 'gravityforms' ); ?>" class="button button-large button-primary"/>

        </form>

        <script type="text/javascript">

            function gwSelectExport( exportName ) {

                var dateRangeElem = jQuery( '#gwmfee_export_date_container' );

                if( exportName != '' ) {
                    dateRangeElem.show();
                } else {
                    dateRangeElem.hide();
                }
            }

            jQuery( document ).ready( function( $ ) {
                $( '#gwmfee_export_date_start, #gwmfee_export_date_end' ).datepicker( { dateFormat: 'yy-mm-dd', changeMonth: true, changeYear: true } );
            } );

        </script>

        <?php
        GFExport::page_footer();

    }

}

function gw_multi_form_entry_exporter_interface() {
    return GW_Multi_Form_Entry_Exporter_Interface::get_instance();
}

class GW_Multi_Form_Entry_Exporter {

    public function __construct( $args = array() ) {

        // make sure we're running the required minimum version of Gravity Forms
        if( ! property_exists( 'GFCommon', 'version' ) || ! version_compare( GFCommon::$version, '1.8', '>=' ) )
            return;

        // set our default arguments, parse against the provided arguments, and store for use throughout the class
        $this->_args = wp_parse_args( $args, array(
            'name'  => false,
            'columns' => array(),
            'date_range' => array(),
            'form_ids' => array()
        ) );

        gw_multi_form_entry_exporter_interface()->register_export( $this );

    }

}

/**
 * J2_Gforms_Multi_ID
 * Setup Settings field so Admin can enter custom IDs
 * Adds a new section to the General Settings Page
 *
 * @since  1.0.0
 */
add_action( 'admin_init', __NAMESPACE__ . '\\J2_Gforms_Multi_ID' );

function J2_Gforms_Multi_ID() {
  add_settings_section('j2_gform_multi_ids', 'Gravity Forms Multi Export', __NAMESPACE__ . '\\j2_gform_multi_id_field', 'general');

  // Add an textbox form field
  add_settings_field(
    'j2_gform_multi_id_field',
    'Gravity Forms Export IDs',
    __NAMESPACE__ . '\\j2_gform_multi_id_field_textbox_callback',
    'general',
    'j2_gform_multi_ids',
    array( 'j2_gform_multi_id_field' )
  );

  // Register the field
  register_setting( 'general', 'j2_gform_multi_id_field', 'esc_attr');
}


/**
 * Callback for Gravity Forms Multi ID field
 * Note that entries should be set up as array values (comma seperated)
 *
 * @since  1.0.0
 */
function j2_gform_multi_id_field() {

  echo  '<p>Enter multiple Gravity Form IDs, seperated by commas.</p>';

}


/**
 * Save Gravity Forms Multi ID Field
 *
 * @since 1.0.0
 */
function j2_gform_multi_id_field_textbox_callback( $args ) {

  $option = get_option($args[0]);
  echo '<input type="text" id="'. $args[0] .'" name="'. $args[0] .'" value="' . $option . '" />';

}

// Create Export
if ( get_option('j2_gform_multi_id_field') ) {

  $id_str = get_option('j2_gform_multi_id_field');
  $id_str = str_replace( ' ', '', $id_str );
  $id_str = preg_replace('/\s+/', '', $id_str);

  if ( false !== strpos( $id_str, ',' ) ) {
    $ids = array_map( 'intval', explode( ',', $id_str ) );
  } else {
    $ids = array( $id_str );
  }

  if ( is_array( $ids ) ) {

    new GW_Multi_Form_Entry_Exporter( array(
        'name'     => 'Gravity Forms Multi Export',
        'form_ids' => $ids
    ) );

  } else {

    return false;

  }

}

