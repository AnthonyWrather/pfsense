<?php
/* $Id$ */
/*
	firewall_virtual_ip.php
	part of pfSense (http://www.pfsense.com/)

	Copyright (C) 2005 Bill Marquette <bill.marquette@gmail.com>.
	All rights reserved.

	Includes code from m0n0wall which is:
	Copyright (C) 2003-2005 Manuel Kasper <mk@neon1.net>.
	All rights reserved.

	Includes code from pfSense which is:
	Copyright (C) 2004-2005 Scott Ullrich <geekgod@pfsense.com>.
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:

	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.

	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/
/*
	pfSense_BUILDER_BINARIES:	/sbin/ifconfig
	pfSense_MODULE:	interfaces
*/

##|+PRIV
##|*IDENT=page-firewall-virtualipaddresses
##|*NAME=Firewall: Virtual IP Addresses page
##|*DESCR=Allow access to the 'Firewall: Virtual IP Addresses' page.
##|*MATCH=firewall_virtual_ip.php*
##|-PRIV

require("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");

if (!is_array($config['virtualip']['vip'])) {
	$config['virtualip']['vip'] = array();
}
$a_vip = &$config['virtualip']['vip'];

if ($_POST) {
	$pconfig = $_POST;

	if ($_POST['apply']) {
		if (file_exists("{$g['tmp_path']}/.firewall_virtual_ip.apply")) {
                        $toapplylist = unserialize(file_get_contents("{$g['tmp_path']}/.firewall_virtual_ip.apply"));
			foreach ($toapplylist as $vid => $ovip) {
				if (!empty($ovip))
					interface_vip_bring_down($ovip);
				if ($a_vip[$vid]) {
                			switch ($a_vip[$vid]['mode']) {
                			case "ipalias":
                        			interface_ipalias_configure($a_vip[$vid]);
                        			break;
                			case "proxyarp":
                        			interface_proxyarp_configure($a_vip[$vid]['interface']);
                        			break;
                			case "carp":
                        			interface_carp_configure($a_vip[$vid]);
						break;
                			case "carpdev-dhcp":
                        			interface_carpdev_configure($a_vip[$vid]);
                        			break;
                			default:
                        			break;
					}
                		}
        		}
			@unlink("{$g['tmp_path']}/.firewall_virtual_ip.apply");
		}
		$retval = 0;
		$retval |= filter_configure();
		$savemsg = get_std_save_message($retval);

		clear_subsystem_dirty('vip');
	}
}

if ($_GET['act'] == "del") {
	if ($a_vip[$_GET['id']]) {
		/* make sure no inbound NAT mappings reference this entry */
		if (is_array($config['nat']['rule'])) {
			foreach ($config['nat']['rule'] as $rule) {
				if($rule['destination']['address'] <> "") {
					if ($rule['destination']['address'] == $a_vip[$_GET['id']]['subnet']) {
						$input_errors[] = gettext("This entry cannot be deleted because it is still referenced by at least one NAT mapping.");
						break;
					}
				}
			}
		}

		if ($a_vip[$_GET['id']]['mode'] == "ipalias") {
			$vipiface = $a_vip[$_GET['id']]['interface'];
			foreach ($a_vip as $vip) {
				if ($vip['interface'] == $vipiface && $vip['mode'] == "carp")
					if (ip_in_subnet($vip['subnet'], gen_subnet($a_vip[$_GET['id']]['subnet'], $a_vip[$_GET['id']]['subnet_bits']) . "/" . $a_vip[$_GET['id']]['subnet_bits']))
						$input_errors[] = gettext("This entry cannot be deleted because it is still referenced by CARP") . " {$vip['descr']}.";
			}
		} else if ($a_vip[$_GET['id']]['mode'] == "carp") {
			$vipiface = "vip{$a_vip[$_GET['id']]['vhid']}";
			foreach ($a_vip as $vip) {
				if ($vipiface == $vip['interface'] && $vip['mode'] == "ipalias")
					$input_errors[] = gettext("This entry cannot be deleted because it is still referenced by ip alias entry") . " {$vip['descr']}.";
			}
		}

		
		if (!$input_errors) {
			// Special case since every proxyarp vip is handled by the same daemon.
			if ($a_vip[$_GET['id']]['mode'] == "proxyarp") {
				$viface = $a_vip[$_GET['id']]['interface'];
				unset($a_vip[$_GET['id']]);
				interface_proxyarp_configure($viface);
			} else {
				interface_vip_bring_down($a_vip[$_GET['id']]);
				unset($a_vip[$_GET['id']]);
			}
			if (count($config['virtualip']['vip']) == 0)
				unset($config['virtualip']['vip']);
			write_config();
			header("Location: firewall_virtual_ip.php");
			exit;
		}
	}
} else if ($_GET['changes'] == "mods")
	$id = $_GET['id'];

$pgtitle = array(gettext("Firewall"),gettext("Virtual IP Addresses"));
include("head.inc");

?>
<body link="#0000CC" vlink="#0000CC" alink="#0000CC">
<?php include("fbegin.inc"); ?>
<form action="firewall_virtual_ip.php" method="post">
<?php 
	if ($input_errors) 
		print_input_errors($input_errors);
	else
	if ($savemsg) 
		print_info_box($savemsg); 
	else
	if (is_subsystem_dirty('vip'))
		print_info_box_np(gettext("The VIP configuration has been changed.")."<br>".gettext("You must apply the changes in order for them to take effect."));
?>
<br>
<table width="100%" border="0" cellpadding="0" cellspacing="0">
  <tr><td class="tabnavtbl">
  <?php
        /* active tabs */
        $tab_array = array();
        $tab_array[] = array(gettext("Virtual IPs"), true, "firewall_virtual_ip.php");
        $tab_array[] = array(gettext("CARP Settings"), false, "pkg_edit.php?xml=carp_settings.xml&id=0");
        display_top_tabs($tab_array);
  ?>
  </td></tr>
  <tr>
	<td><input type="hidden" id="id" name="id" value="<? echo $id; ?>"></td>
  </tr>
  <tr>
    <td>
	<div id="mainarea">
              <table class="tabcont" width="100%" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td width="30%" class="listhdrr"><?=gettext("Virtual IP address");?></td>
                  <td width="10%" class="listhdrr"><?=gettext("Type");?></td>
                  <td width="40%" class="listhdr"><?=gettext("Description");?></td>
                  <td width="10%" class="list">
                    <table border="0" cellspacing="0" cellpadding="1">
                      <tr>
			<td width="17"></td>
                        <td valign="middle"><a href="firewall_virtual_ip_edit.php"><img src="./themes/<?= $g['theme']; ?>/images/icons/icon_plus.gif" width="17" height="17" border="0"></a></td>
                      </tr>
                    </table>
		  </td>
		</tr>
			  <?php $i = 0; foreach ($a_vip as $vipent): ?>
			  <?php if($vipent['subnet'] <> "" or $vipent['range'] <> "" or
			        $vipent['subnet_bits'] <> "" or (isset($vipent['range']['from']) && $vipent['range']['from'] <> "") or $vipent['mode'] == "carpdev-dhcp"): ?>
                <tr>
                  <td class="listlr" ondblclick="document.location='firewall_virtual_ip_edit.php?id=<?=$i;?>';">
					<?php	if (($vipent['type'] == "single") || ($vipent['type'] == "network"))
								if($vipent['subnet_bits'])
									echo "{$vipent['subnet']}/{$vipent['subnet_bits']}";
							if ($vipent['type'] == "range")
								echo "{$vipent['range']['from']}-{$vipent['range']['to']}";
					?>
					<?php if($vipent['mode'] == "carpdev-dhcp") echo "DHCP"; ?>
					<?php if($vipent['mode'] == "carp" or $vipent['mode'] == "carpdev-dhcp") echo " (vhid {$vipent['vhid']})"; ?>
                  </td>
                  <td class="listr" align="center" ondblclick="document.location='firewall_virtual_ip_edit.php?id=<?=$i;?>';">
                    <? if($vipent['mode'] == "proxyarp") echo "<img src='./themes/".$g['theme']."/images/icons/icon_parp.gif' title='Proxy ARP'>"; elseif($vipent['mode'] == "carp" or $vipent['mode'] == "carpdev-dhcp") echo "<img src='./themes/".$g['theme']."/images/icons/icon_carp.gif' title='CARP'>"; elseif($vipent['mode'] == "other") echo "<img src='./themes/".$g['theme']."/images/icons/icon_other.gif' title='Other'>"; elseif($vipent['mode'] == "ipalias") echo "<img src='./themes/".$g['theme']."/images/icons/icon_ifalias.gif' title='IP Alias'>";?>
                  </td>
                  <td class="listbg" ondblclick="document.location='firewall_virtual_ip_edit.php?id=<?=$i;?>';">
                    <?=htmlspecialchars($vipent['descr']);?>&nbsp;
                  </td>
                  <td class="list" nowrap>
                    <table border="0" cellspacing="0" cellpadding="1">
                      <tr>
                        <td valign="middle"><a href="firewall_virtual_ip_edit.php?id=<?=$i;?>"><img src="./themes/<?= $g['theme']; ?>/images/icons/icon_e.gif" width="17" height="17" border="0"></a></td>
                        <td valign="middle"><a href="firewall_virtual_ip.php?act=del&id=<?=$i;?>" onclick="return confirm('<?=gettext('Do you really want to delete this entry?');?>')"><img src="./themes/<?= $g['theme']; ?>/images/icons/icon_x.gif" width="17" height="17" border="0"></a></td>
                      </tr>
                    </table>
                  </td>
                </tr>
		<?php endif; ?>
                <?php $i++; endforeach; ?>
                <tr>
                  <td class="list" colspan="3"></td>
                  <td class="list">
                    <table border="0" cellspacing="0" cellpadding="1">
                      <tr>
			<td width="17"></td>
                        <td valign="middle"><a href="firewall_virtual_ip_edit.php"><img src="./themes/<?= $g['theme']; ?>/images/icons/icon_plus.gif" width="17" height="17" border="0"></a></td>
                      </tr>
                    </table>
                  </td>
                </tr>
		<tr>
		  <td colspan="4">
		      <p><span class="vexpl"><span class="red"><strong><?=gettext("Note:");?><br>
                      </strong></span><?=gettext("The virtual IP addresses defined on this page may be used in");?><a href="firewall_nat.php"> <?=gettext("NAT"); ?> </a><?=gettext("mappings.");?><br>
                      <?=gettext("You can check the status of your CARP Virtual IPs and interfaces ");?><a href="carp_status.php"><?=gettext("here");?></a>.</span></p>
		  </td>
		</tr>
              </table>
	   </div>
	</table>
            </form>
<?php include("fend.inc"); ?>
</body>
</html>
