<?php
require_once "../config.php";

use \Tsugi\Core\LTIX;

// Sanity checks - require all three
$LAUNCH = LTIX::requireData();

$p = $CFG->dbprefix;
//Retrieve the other rows
$stmt = $PDOX->prepare("SELECT lat,lng,{$p}context_map.email AS allow_email, name AS allow_name,
            {$p}context_map.first AS allow_first, displayname, {$p}lti_user.email AS email
        FROM {$p}context_map
        JOIN {$p}lti_user
        ON {$p}context_map.user_id = {$p}lti_user.user_id
        WHERE context_id = :CID AND {$p}context_map.user_id <> :UID");
$stmt->execute(array(":CID" => $CONTEXT->id, ":UID" => $USER->id));
$points = array();
while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
    if ( !isset($row['lat']) ) continue;
    if ( !isset($row['lng']) ) continue;
    $lat = $row['lat']+0;
    $lng = $row['lng']+0;
    if ( $lat == 0 && $lng == 0 ) continue;
    if ( abs($lat) > 85 ) $lat = 0;
    if ( abs($lng) > 180 ) $lng = 179.9;
    $email = $row['email'];
    $name = $row['displayname'];
    if ( ! $USER->instructor ) {
        if ( $row['allow_name'] == 1 ) $name = $name;  // Show it all
        else if ( $row['allow_first'] == 1 ) $name = $USER->getFirstName($name);
        else $name = '';
        if ( $row['allow_email'] != 1 ) $email = '';
    }
    $info = ($row['allow_name'] == 1 || $row['allow_first'] == 1 || $row['allow_email'] == 1)+0;
    $display = $name;
    if ( strlen($email) > 0 ) {
        if ( strlen($display) > 0 ) {
            $display .= ' ('.$email.')';
        } else {
            $display = $email;
        }
    }

    $points[] = array($lat, $lng, $display, $info);
}
;
// Retrieve our row
$stmt = $PDOX->prepare("SELECT lat,lng,name,first,email FROM {$p}context_map
        WHERE context_id = :CID AND user_id = :UID");
$stmt->execute(array(":CID" => $CONTEXT->id, ":UID" => $USER->id));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
// The default for latitude and longitude
$lat = 42.279070216140425;
$lng = -83.73981015789798;
$firsttime = true;
if ( $row !== false ) {
    $firsttime = false;
    if ( isset($row['lat']) && abs($row['lat']) < 85 ) $lat = $row['lat'];
    if ( isset($row['lng']) && abs($row['lng']) < 180 ) $lng = $row['lng'];
}
$display = $USER->getNameAndEmail();
$firstname = $USER->getFirstName();

$OUTPUT->header();
?>
<script src="https://maps.googleapis.com/maps/api/js?v=3.exp&key=<?= $CFG->google_map_api_key ?>"></script>
<script type="text/javascript">
var map;

// https://developers.google.com/maps/documentation/javascript/reference
function initialize_map() {
  var myLatlng = new google.maps.LatLng(<?php echo($lat.", ".$lng); ?>);
  window.console && console.log("Building map...");

  var myOptions = {
     zoom: 3,
     center: myLatlng,
     mapTypeId: google.maps.MapTypeId.ROADMAP
  }

  map = new google.maps.Map(document.getElementById("map_canvas"), myOptions);

  var marker = new google.maps.Marker({
    draggable: true,
    position: myLatlng,
    map: map,
    title: "Drag the icon to change your location.  <?php if ($display) echo('Double click to set preferences.'); ?>"
  });

  google.maps.event.addListener(marker, 'dragend', function (event) {
    window.console && console.log(this.getPosition());
    $.post( '<?php echo(addSession('update.php')); ?>',
      { 'lat': this.getPosition().lat(), 'lng' : this.getPosition().lng() },
      function( data ) {
          window.console && console.log(data);
      }
    ).error( function() {
      window.console && console.log('error');
    });
  });

<?php if ( $display ) { ?>
  google.maps.event.addListener(marker, 'dblclick', function (event) {
    $('#prefs').modal();
  });
<?php } ?>

  // Add the other points
  window.console && console.log("Loading "+other_points.length+" points");
  for ( var i = 0; i < other_points.length; i++ ) {
    var row = other_points[i];
    // if ( i < 3 ) { alert(row); }
    var newLatlng = new google.maps.LatLng(row[0], row[1]);
    var iconpath = '<?php echo($CFG->staticroot); ?>/img/icons/';
    console.log(row);
    var icon = row[3] ? 'green-dot.png' : 'green.png';
    var marker = new google.maps.Marker({
      position: newLatlng,
      map: map,
      icon: iconpath + icon,
      title : row[2]
     });
  }
}
// Load the other points
other_points =
<?php echo( json_encode($points));?>
;
</script>
<?php
$OUTPUT->bodyStart();
if ( $display ) {
?>
<div class="modal fade" id="prefs">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h4 class="modal-title"><?php echo(htmlent_utf8($display)); ?></h4>
      </div>
      <div class="modal-body">
        <p>Map Preferences
        <img id="spinner" src="<?php echo($OUTPUT->getSpinnerUrl()); ?>" style="display: none">
        <span id="save_fail" style="display:none; color:red">Unable to save preferences</span>
        </p>
        <form id="prefs_form">
            <?php if ( $firstname === false ) { ?>
                <input type="checkbox" name="allow_first" style="display:hidden">
            <?php } else { ?>
                <input type="checkbox" name="allow_first" <?php
                if ( $row['first'] == 1 ) echo("checked"); ?>
            >
            Share your first name (<?php echo(htmlent_utf8($firstname)); ?>) on the map<br/>
            <?php } ?>
            <input type="checkbox" name="allow_name" <?php
                if ( ! isset($USER->displayname) ) echo(' style="display:hidden"');
                else if ( $row['name'] == 1 ) echo("checked"); ?>
            >
            Share your full name on the map<br/>
            <input type="checkbox" name="allow_email" <?php
                if ( ! isset($USER->email) ) echo(' style="display:hidden"');
                else if ( $row['email'] == 1 ) echo("checked"); ?>
            >
            Share your email on the map<br/>
            </p><p>To set your location drag the large red pin to your location.  The
            new location is updated as soon as you move the pin.  Your location will
            always appear to you as a large, red pin.   When others see you, they will
            see a green pin.</p>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
        <button type="button" id="prefs_save" class="btn btn-primary">Save changes</button>
      </div>
    </div><!-- /.modal-content -->
  </div><!-- /.modal-dialog -->
</div><!-- /.modal -->
<?php } ?>
<div class="modal fade" id="howdy">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h4 class="modal-title"><?php $OUTPUT->welcomeUserCourse(); ?></h4>
      </div>
      <div class="modal-body">
        <p>This is a map of the participants in the course who have chosen to share their location.
  Move the large red pointer on the map until it is at the correct location.
  If you are concerned about privacy, simply put the
  location somewhere <i>near</i> where you live.  Perhaps in the same country, state, or city
  instead of your exact location.  If you do not want to share your location, simply do
  not move the red pointer.  All of the other students are shown as green pins.  When you
  are looking at the map, you will always be the large, red pin.  When others see you, they will
  see a green pin.  The green pins with dots are the ones who have shared some of their information.
  Hover over these pins with dots to see the other student's information.
<?php if ( $display ) { ?>
</p><p>
You can choose to be anonymous or share your first name, full name or email if you would like.
To configure your privacy options double-click on the large red pointer that represents your
location.
<?php } ?>
        </p>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-dismiss="modal">Go to map</button>
<?php $OUTPUT->exitButton("Cancel"); ?>
      </div>
    </div><!-- /.modal-content -->
  </div><!-- /.modal-dialog -->
</div><!-- /.modal -->
<div id="map_canvas" style="margin: 10px; width:95%; height:600px"></div>
<?php
$OUTPUT->footerStart();
?>
<script type="text/javascript">
$(document).ready(function() {
    initialize_map();

    $('#prefs_save').click(function(event) {
        $('#spinner').show();
        var form = $('#prefs_form');
        var allow_name = form.find('input[name="allow_name"]').is(':checked') ? 1 : 0 ;
        var allow_first = form.find('input[name="allow_first"]').is(':checked') ? 1 : 0 ;
        var allow_email = form.find('input[name="allow_email"]').is(':checked') ? 1 : 0 ;
        window.console && console.log('Sending POST');
        $.post( '<?php echo(addSession('update.php')); ?>',
           { 'allow_name': allow_name, 'allow_first': allow_first, 'allow_email': allow_email },
          function( data ) {
              window.console && console.log(data);
              $('#spinner').hide();
              $('#prefs').modal('hide');
          }
        ).error( function() {
            window.console && console.log('POST returned error');
            $('#spinner').hide();
            $('#save_fail').show();
        });
        return false;
      });
    $('#howdy').modal();
} );
</script>
<?php
$OUTPUT->footerEnd();

