<?php
/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL, 
	as published by the Free Software Foundation, either version 3 
	of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/

$page_security = 'SA_SALESORDER';
$path_to_root="../..";
include_once($path_to_root . "/includes/session.inc");

include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/data_checks.inc");
include_once($path_to_root . "/includes/db/crm_contacts_db.inc");

// ---------------------------------------------------------------------

set_posts(array('description', 'debtor_no', 'noheader', 'action', 'branch'));


add_css_file('https://unpkg.com/leaflet@1.3.1/dist/leaflet.css');
// does not work
// add_css_file('https://github.com/apmuthu/FA24extensions/files/2130882/leaflet.css.txt');

function getBranchInfo($branch)
{
    $sql = "SELECT d.debtor_no, d.name AS cust_name, b.br_address, b.br_post_address, b.branch_code
        FROM ".TB_PREF."cust_branch b
        LEFT JOIN ".TB_PREF."debtors_master d ON d.debtor_no=b.debtor_no
        WHERE b.branch_code=".db_escape($branch);
    return db_query($sql,"No transactions were returned");
}

function getTransactions($cat, $from, $to, $tax_group_id, $customer_id, $description)
{
    if (!empty($from)) {
        $fromdate = date2sql($from);
        $todate = date2sql($to);
    } else {
        $fromdate = date2sql(add_years(Today(), -1));
        $todate = date2sql(Today());
    }

    $sql = "SELECT d.debtor_no, d.name AS cust_name, dt.type, dt.trans_no,  dt.tran_date, cb.br_address, cb.br_post_address, dt.branch_code
        FROM ".TB_PREF."debtor_trans dt
            LEFT JOIN ".TB_PREF."debtors_master d ON d.debtor_no=dt.debtor_no
            LEFT JOIN ".TB_PREF."cust_branch cb ON dt.branch_code=cb.branch_code
            LEFT JOIN ".TB_PREF."tax_groups st ON cb.tax_group_id=st.id
            LEFT JOIN ".TB_PREF."sales_order_details sod
                ON dt.order_ = sod.order_no
            LEFT JOIN ".TB_PREF."stock_master sm
                ON sm.stock_id = sod.stk_code";

    $sql .= " WHERE (dt.type=".ST_SALESINVOICE."
        OR dt.type=".ST_CUSTCREDIT." )";

    if (!empty($cat))
        $sql .= " AND sm.category_id = " . db_escape($cat);

    if (!empty($description))
        $sql .= "AND LOCATE(".db_escape($description).",sm.description) != 0 ";

    if (!empty($fromdate))
        $sql .= "AND dt.tran_date >=".db_escape($fromdate)." AND dt.tran_date<=".db_escape($todate);

    if (!empty($tax_group_id))
        $sql .= " AND tax_group_id = '" . $tax_group_id . "'";

    $sql .= " GROUP BY debtor_no";

    $sql .= " UNION
        SELECT d.debtor_no, d.name AS cust_name, 'a', 'b', 'c', cb.br_address, cb.br_post_address, cb.branch_code
            FROM ".TB_PREF."debtors_master d
            LEFT JOIN ".TB_PREF."cust_branch cb ON d.debtor_no=cb.debtor_no
        WHERE d.debtor_no=".db_escape($customer_id);

    $sql .= " ORDER BY cust_name";
    return db_query($sql,"No transactions were returned");
}

function getLatLong($address)
{
    $sql = "SELECT latlong, lookup_date
        FROM ".TB_PREF."address_map
        WHERE address =".db_escape($address);
    $result = db_query($sql,"No transactions were returned");
    return db_fetch($result);
}


function getCensusGeoCode($address)
{
    $request_url = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress?address=" . urlencode($address) . "&benchmark=4";

    // $request_url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=xml";

    // this should work but not with openstreet
    // $xml = simplexml_load_file($request_url) or die("url not loading");

    // instead use curl
    $ch = curl_init($request_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Identify the request User Agent as Chrome - any real browser, or perhaps any value may work
    // depending on the resource you are trying to hit
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $feed = curl_exec($ch);
    if ($feed === false) {
        display_error("curl_exec failed");
        return ",";
    }

    $data=strpos($feed, "Coordinates:");
    if ($data === false)
        return ",";
    $feed = substr($feed, $data);

    $X = strpos($feed, "X:");
    $Y = strpos($feed, "Y:");
    $br = strpos($feed, "<br");
    $lng = substr($feed, $X+3, $Y-$X-4);
    $lat = substr($feed, $Y+3, $br-$Y-3);

    $latlong = $lat . "," . $lng;
    return $latlong;
}

function getGeoCode($address)
{
    $request_url = "http://maps.googleapis.com/maps/api/geocode/xml?address=" . urlencode($address) . "&sensor=false";

    // $request_url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=xml";

    // this should work but not with openstreet
    // $xml = simplexml_load_file($request_url) or die("url not loading");

    // instead use curl
    $ch = curl_init($request_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Identify the request User Agent as Chrome - any real browser, or perhaps any value may work
    // depending on the resource you are trying to hit
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
//print($address);
//die();
    $feed = curl_exec($ch);
    if ($feed === false) {
        display_error("curl_exec failed");
        return ",";
    }

    $xml = new SimpleXMLElement($feed);

//display_notification($request_url);
//display_notification(print_r($xml, true));

    if (isset($xml->place)) {
      // Successful geocode
      $lat = $xml->place['lat'];
      $lng = $xml->place['lon'];
      return $lat . "," . $lng;
    }

    return ",";
}
function getSalesItems($cat, $debtor_no = null, $from = null, $to = null)
{
    if (!empty($from)) {
        $fromdate = date2sql($from);
        $todate = date2sql($to);
    }

    $sql = "SELECT stk_code, SUBSTR(sm.description,1, LENGTH(sm.description)-1-LENGTH(SUBSTRING_INDEX(sm.description, ' ', -1))) as description
        FROM ".TB_PREF."debtor_trans dt
            LEFT JOIN ".TB_PREF."voided as v
                ON dt.trans_no=v.id AND dt.type=v.type
            LEFT JOIN ".TB_PREF."debtors_master d
                ON d.debtor_no=dt.debtor_no
            LEFT JOIN ".TB_PREF."sales_order_details sod
                ON dt.order_ = sod.order_no
            LEFT JOIN ".TB_PREF."stock_master sm
                ON sm.stock_id = sod.stk_code

        WHERE ISNULL(v.date_)
            AND (dt.type=".ST_SALESINVOICE."
                OR dt.type=".ST_CUSTCREDIT.") ";

        if (!empty($debtor_no))
            $sql .= " AND d.debtor_no = " . db_escape($debtor_no);

        if (!empty($cat))
            $sql .= " AND sm.category_id = " . db_escape($cat);

    if (!empty($from))
        $sql .= "AND dt.tran_date >=".db_escape($fromdate)." AND dt.tran_date<=".db_escape($todate);

    $sql .= " GROUP BY SUBSTR(sm.description,1,LENGTH(sm.description)-1-LENGTH(SUBSTRING_INDEX(sm.description, ' ', -1))) ORDER BY sm.description";

    return db_query($sql,"No transactions were returned");
}

$centerLat = $centerLong = $centerCount = 0;
function clientarray_string($cat, $branch, $description, $tax_group_id, $customer_id)
{
    global $centerLat, $centerLong, $centerCount;
	$clientArray="var clientArray = new Array();\n";

	$count=0;

    if ($branch != "")
        $res = getBranchInfo($branch);
    else
        $res = getTransactions($cat, null, null, $tax_group_id, $customer_id, get_post('description'));

    while ($cust=db_fetch($res)) {

        $old_address = trim($cust["br_post_address"]);
        if ($old_address == "")
            continue;
        $address = preg_replace("/^[^0-9]*/", "", $old_address);
        if ($old_address != $address
            && !isset($_POST['noheader'])) {
            display_notification($cust["cust_name"] . "$old_address => $address");
        }
        $address = str_replace(array("\n", "\r"), ' ', $address);
        $lastchar = substr($address, strlen($address)-1,1);
        if (!is_numeric($lastchar)) {
            // No zip code? Skipping address
            if (!isset($_POST['noheader']))
                display_notification($cust["cust_name"] . "missing zipcode for $address");
            continue;
        }

        // #suite numbers confuse openstreetmap/nominatim 
        $address = preg_replace("/#[0-9]*/", "", $address);

        $latlong = '';
        $row = getLatLong($address);
        if ($row) {
            $latlong = $row['latlong'];
            // if you query the server more than once per day for the same bad address
            // you risk your IP getting blacklisted
            if ($latlong == ",") {
                if ($row['lookup_date'] = date2sql(Today())) {
                    if (!isset($_POST['noheader']))
                        display_notification("Lookup failed once today:" . $cust["cust_name"] . " at " . $address);
                    $latlong = $centerLat . "," . $centerLong;
                }
            }
        }

        if ($latlong == '' || $latlong == ',') {
            $latlong = getGeocode($address);
            if ($latlong == ',')
                $latlong = getCensusGeocode($address);

            if ($latlong != ',' || !$row) {
                $sql = "INSERT ".TB_PREF."address_map (address, latlong, lookup_date) VALUES('" . $address . "','" . $latlong . "','" . date2sql(Today()) . "')";
                db_query($sql,"No transactions were returned");
            }

            if ($latlong == ',') {
                $latlong = $centerLat . "," . $centerLong;

                if (!isset($_POST['noheader']))
                    display_notification("bad geocode for " . $cust["cust_name"] . " at " . $address);
            }
        }

        $foo = explode(",", $latlong);
        $lat = $foo[0];
        $lng = $foo[1];

        if ($cust['debtor_no'] == $customer_id) {
            $centerLat = $lat;
            $centerLong = $lng;
            $centerCount = $count;
        }

        $crm = get_customer_contacts($cust["debtor_no"]);

        $clientArray .= "clientArray[" . $count . "] = new Array(";
        $clientArray .= "\"" . $cust["cust_name"] . "\","; 
        $clientArray .= "\"" . $address . "\","; 
        $clientArray .= "\"" . $lat . "\","; 
        $clientArray .= "\"" . $lng . "\","; 
        $clientArray .= "\"" . $crm[0]["phone"] . "\",";
        $clientArray .= "\"" . $cust["debtor_no"] . "\"";
        $clientArray .= ");\n";


        $count++;
    }

	return str_replace("'", "\\'", $clientArray);
}

// ---------------------------------------------------------------------
global $db_connections;
$cur_prefix = $db_connections[$_SESSION["wa_current_user"]->cur_con]['tbpref'];

$sql          = "SHOW TABLES";
$result       = db_query($sql, "could not show tables");
$found        = 0;
$config_found = 0;
while (($row = db_fetch_row($result))) {
    if ($row[0] == $cur_prefix."address_map") $found = 1;
    if ($row[0] == $cur_prefix."address_map_config") $config_found = 1;
}

if (!$found) {
        $sql = "DROP TABLE IF EXISTS ".TB_PREF."address_map";
        db_query($sql, "Error dropping table");
        $sql = "CREATE TABLE ".TB_PREF."address_map (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `address` varchar(255) NOT NULL,
            `latlong` varchar(32) NOT NULL default '',
            `lookup_date` date NOT NULL default '0000-00-00',
            `jurisdiction` varchar(32) NOT NULL default '',
            UNIQUE(address),
            PRIMARY KEY  (`id`)) ENGINE=InnoDB";
        db_query($sql, "Error creating table");
}

if ($config_found) {
    // Get Configuration variables
    $sql     = "SELECT * FROM ".TB_PREF."address_map_config WHERE name = 'tile_server'";
    $result  = db_query($sql, "could not get tile server");
    $row     = db_fetch_row($result);
    if ($row)
        $tile_Server = $row[1];

    $sql     = "SELECT * FROM ".TB_PREF."address_map_config WHERE name = 'cat'";
    $result  = db_query($sql, "could not get category");
    $row     = db_fetch_row($result);
    if ($row)
        $cat_ = $row[1];

    $sql     = "SELECT * FROM ".TB_PREF."address_map_config WHERE name = 'tax_group_id'";
    $result  = db_query($sql, "could not get tax_group");
    $row     = db_fetch_row($result);
    if ($row)
        $tax_group_id_ = $row[1];

    $sql     = "SELECT * FROM ".TB_PREF."address_map_config WHERE name = 'customer_id'";
    $result  = db_query($sql, "could not get customer");
    $row     = db_fetch_row($result);
    if ($row)
        $customer_id_ = $row[1];
}

// -----------------------------------------------------------------


    // Create Table
    if (get_post('action') == 'create') {
        $sql = "DROP TABLE IF EXISTS ".TB_PREF."address_map_config";
        db_query($sql, "Error dropping table");
        $sql = "CREATE TABLE ".TB_PREF."address_map_config ( `name` char(15) NOT NULL default '', " .
               " `value` varchar(256) NOT NULL default '', PRIMARY KEY  (`name`)) ENGINE=InnoDB";
        db_query($sql, "Error creating table");
        header("Location: sales_map.php?action=show");
    }

    if (get_post('action') == 'update') {

            $customer_id = get_post('customer_id');
            $tileServer = get_post('tileServer');
            $cat = get_post('category');
            $tax_group_id = get_post('tax_group_id');

            if ($tileServer == '') $sql = "DELETE FROM ".TB_PREF."address_map_config WHERE name = 'tile_server'";
            else if (!isset($tile_Server)) $sql = "INSERT INTO ".TB_PREF."address_map_config (name, value) VALUES ('tile_server', ".db_escape($tileServer).")";
            else $sql = "UPDATE  ".TB_PREF."address_map_config SET value = ".db_escape($tileServer)." WHERE name = 'tile_server'";
            db_query($sql, "Update 'tile_server'");

            if ($cat == '') $sql = "DELETE FROM ".TB_PREF."address_map_config WHERE name = 'cat'";
            else if (!isset($cat_)) $sql = "INSERT INTO ".TB_PREF."address_map_config (name, value) VALUES ('cat', ".db_escape($cat).")";
            else $sql = "UPDATE  ".TB_PREF."address_map_config SET value = ".db_escape($cat)." WHERE name = 'cat'";
            db_query($sql, "Update 'cat'");

            if ($tax_group_id == '') $sql = "DELETE FROM ".TB_PREF."address_map_config WHERE name = 'tax_group_id'";
            else if (!isset($tax_group_id_)) $sql = "INSERT INTO ".TB_PREF."address_map_config (name, value) VALUES ('tax_group_id', ".db_escape($tax_group_id).")";
            else $sql = "UPDATE  ".TB_PREF."address_map_config SET value = ".db_escape($tax_group_id)." WHERE name = 'tax_group_id'";
            db_query($sql, "Update 'tax_group_id'");

            if ($customer_id == '') $sql = "DELETE FROM ".TB_PREF."address_map_config WHERE name = 'customer_id'";
            else if (!isset($customer_id_)) $sql = "INSERT INTO ".TB_PREF."address_map_config (name, value) VALUES ('customer_id', ".db_escape($customer_id).")";
            else $sql = "UPDATE  ".TB_PREF."address_map_config SET value = ".db_escape($customer_id)." WHERE name = 'customer_id'";
            db_query($sql, "Update 'customer_id'");

    } else {

// Default Configuration:
// Most importantly, you MUST change the tile server
// (read the usage terms).
        if (isset($tile_Server))
            $tileServer = $tile_Server;
        else
            $tileServer = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";

        if (isset($cat_))
            $cat = $cat_;
        else
            $cat = "";
        if (isset($tax_group_id_))
            $tax_group_id = $tax_group_id_;
        else
            $tax_group_id = "";
        if (isset($customer_id_))
            $customer_id = $customer_id_;
        else
            $customer_id = "";
    }

// ----------------------------------------------------------------
if (get_post('debtor_no')) {
    page(_($help_context = "Sales Map"), true, false, "", "");
    print get_customer_name(get_post('debtor_no')) . "<br><br>";
    $res = getSalesItems($cat, get_post('debtor_no'));
    while ($item=db_fetch($res)) {
			print $item['description'] . "<br>";
	}
    exit();
}

// ----------------------------------------------------------------

if (get_post('action') == 'show') {
    page(_($help_context = "Sales Map"), get_post('noheader'), false, "", "");
    start_form(true);
    start_table(TABLESTYLE);

    $th = array("Function", "Description");
    table_header($th);

    $k = 0;

    alt_table_row_color($k);

    label_cell("Table Status");
    if ($config_found) $table_st = "Found";
    else $table_st = "<font color=red>Not Found</font>";
    label_cell($table_st);
    end_row();

    if ($config_found) {
        text_row("Tile Server<br>(See usage policy)", 'tileServer', $tileServer, 40, 100);
        customer_list_row("Map Center Customer", 'customer_id', $customer_id);
        stock_categories_list_row("Item Category", 'category', $cat, "All Categories");
        tax_groups_list_row("Tax Group", 'tax_group_id', $tax_group_id);
    }

    end_table(1);

    if (!$config_found) {
        hidden('action', 'create');
        submit_center('create', 'Create Table');
    } else {
        hidden('action', 'update');
        submit_center('update', 'Update Mysql');
    }

    end_form();

    end_page();
    exit();
}

define ('SCRIPT', '
' . clientarray_string($cat, get_post('branch'), get_post('description'), $tax_group_id, $customer_id) . '
  var side_bar_html = "<DIV id=\'title\'></DIV>";

function map_init()
{
    var map = L.map("map_canvas").setView([' . $centerLat . ',' . $centerLong . '], 13);
    L.tileLayer("' . $tileServer . '", {
        attribution: "&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors"
    }).addTo(map);

    for (var i = 0; i < clientArray.length; i++)
        codeAddress(map, i);

	//side bar html into side bar div
	document.getElementById("side_bar").innerHTML = side_bar_html;

    clickList('. $centerCount . ');
}

function popup(mylink, windowname)
{
if (! window.focus)return true;
var href;
if (typeof(mylink) == \'string\')
   href=mylink;
else
   href=mylink.href;
window.open(href, windowname, \'width=500,height=400,scrollbars=yes\');
return false;
}

function decodeHtml(html) {
    var txt = document.createElement("textarea");
    txt.innerHTML = html;
    return txt.value;
}

function formatXml(xml, tab)
{
    var formatted = "", indent= "";
    tab = tab || "\t";
    xml.split(/>\s*</).forEach(function(node) {
        if (node.match( /^\/\w/ )) indent = indent.substring(tab.length); // decrease indent by one tab
        formatted += indent + "<" + node + ">\r\n";
        if (node.match( /^<?\w[^>]*[^\/]$/ )) indent += tab;              // increase indent
    });
    return formatted.substring(1, formatted.length-3);
}

var markers = [];
var names = [];
var addresses = [];
var drawnItems = new L.FeatureGroup();

function getkml()
{
    var json = drawnItems.toGeoJSON();

    for (i=0; i < names.length; i++) {
        json.features[i].properties.name=decodeHtml(names[i]);
        json.features[i].properties.address=decodeHtml(addresses[i]);
    }
    var kml = tokml(json);
    return formatXml(kml, "    ");
}

function downloadkml(filename, text) {
  var element = document.createElement("a");
  element.setAttribute("href", "data:text/plain;charset=utf-8," + encodeURIComponent(text));
  element.setAttribute("download", filename);

  element.style.display = "none";
  document.body.appendChild(element);

  element.click();

  document.body.removeChild(element);
}

function clickList(i) {
    markers[i].openPopup();
}


function codeAddress(map, i) {
	var htmlListing = "<P><H2 onclick=clickList(" + i + ") style=\'margin-bottom:2px;\'><a href=" + window.location.href +"#title>" + clientArray[i][0] + "</a></H2>"	//name
					+ clientArray[i][1] +  "<br>"  //address
                    + "<a href=\"tel:" + clientArray[i][4] + "\">" + clientArray[i][4] + "</a><br>" // phone
 + "<a href=" + location.protocol + "//" + location.host + location.pathname + "?debtor_no=" + clientArray[i][5] + " onClick=\'return popup(this, \"Items Carried\")\'>Items Carried</a>" ;

	side_bar_html += htmlListing;	
    										
	var markerText =  "<div STYLE=\'background-color:#00068f;font-weight:bold\'><font size=3 face=\'trebuchet MS\' color=white>&nbsp;"
		        + clientArray[i][0] + "<font size=2 color=blue></div>" 
						        + clientArray[i][1] + "<br>"
                                + "<a href=\"tel:" + clientArray[i][4] + "\">" + clientArray[i][4] + "</a></font>"; // phone
    										
    marker = L.marker([clientArray[i][2], clientArray[i][3]]);
    marker.addTo(map)
        .bindPopup(markerText)
        .openPopup();
    drawnItems.addLayer(marker);

    names[i] = clientArray[i][0];
    addresses[i] = clientArray[i][1];
    markers[i] = marker;    // store for list click
}

function stockFilter()
{
    var ddl = document.getElementById("description");
    var description = ddl.options[ddl.selectedIndex].value;
    window.location.href = location.protocol + "//" + location.host + location.pathname
        + "?description=" + description' .  (isset($_POST['noheader']) ? '+ "&noheader=1"' : '') .  '
}

Behaviour.addLoadEvent(map_init);

');

$js = "";
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(800, 500);
if (user_use_date_picker())
	$js .= get_js_date_picker();
$js .= file_get_contents("https://unpkg.com/leaflet@1.3.1/dist/leaflet.js");
$js .= file_get_contents("https://raw.githubusercontent.com/mapbox/tokml/master/tokml.js");
$js .= get_js_history(array('debtor_no', 'description'));
$js .= SCRIPT;

page(_($help_context = "Sales Map"), get_post('noheader'), false, "", $js);


start_form();
start_table(TABLESTYLE);

// Ajax does not work with map_canvas, so use direct javascript

$sel = "<label for=\"description\"> Item Options </label>
<select id=\"description\" name=\"description\" onchange=\"stockFilter()\">";
    $sel .= "<option value=\"\">All Items\n";
$res = getSalesItems($cat);
while ($item=db_fetch($res)) {
        if ($item['description'] == get_post('description'))
            $selected = " selected";
        else
            $selected = "";
        $sel .= "<option value=\"" . $item['description'] . "\"" . $selected . ">" . $item['description'] . "\n";
}

$sel .= "</select>";

table_header(array("Sales Locations", ($cat == "" ? "All Categories" : get_category_name($cat)) . " " . $sel));
?>


<tr><td class="infoBox" valign=top>
  <div id="side_bar" style="OVERFLOW: auto; WIDTH: 200px; HEIGHT: 500px; BACKGROUND-COLOR: #ffffff"></div>
</td>
<td valign=top>
<style>
.map_canvas {
    height: 500px;
    width: 600px;
}
</style>

<?php
div_start("map_canvas");
div_end();
?>
</td></tr>

<tr>
<td class="infoBoxHeading" vAlign="top" align="center" colSpan="2">
Click and hold to drag map.
Click on marker for more information.
</td>
</tr>

<tbody id="config">
<tr><td align="center" colspan=2>
<?php
    hyperlink_params($_SERVER['PHP_SELF'], _("Configuration"), "action=show", false);
?>
<a onclick='downloadkml("sales.kml", getkml());'>Download KML</a>
</td>
</tr>
</tbody>
<?php

end_table(1);

end_page(get_post('noheader'));

?>
