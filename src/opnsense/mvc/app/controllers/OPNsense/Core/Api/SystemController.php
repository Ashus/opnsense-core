<?php

/**
 *    Copyright (C) 2019-2022 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace OPNsense\Core\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\ACL;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;

/**
 * Class SystemController
 * @package OPNsense\Core
 */
class SystemController extends ApiControllerBase
{
    private function formatUptime($uptime)
    {
        $days = floor($uptime / (3600 * 24));
        $hours = floor(($uptime % (3600 * 24)) / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;

        if ($days > 0) {
            $plural = $days > 1 ? gettext("days") : gettext("day");
            return sprintf(
                "%d %s, %02d:%02d:%02d",
                $days,
                $plural,
                $hours,
                $minutes,
                $seconds
            );
        } else {
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
        }
    }

    public function haltAction()
    {
        if ($this->request->isPost()) {
            (new Backend())->configdRun('system halt', true);
            return ['status' => 'ok'];
        } else {
            return ['status' => 'failed'];
        }
    }

    public function rebootAction()
    {
        if ($this->request->isPost()) {
            (new Backend())->configdRun('system reboot', true);
            return ['status' => 'ok'];
        } else {
            return ['status' => 'failed'];
        }
    }

    public function statusAction()
    {
        $this->sessionClose();

        $response = ["status" => "failed"];

        $backend = new Backend();
        $statuses = json_decode(trim($backend->configdRun('system status')), true);
        if ($statuses) {
            $order = [-1 => 'Error', 0 => 'Warning', 1 => 'Notice', 2 => 'OK'];

            $acl = new ACL();
            foreach ($statuses as $subsystem => $status) {
                $statuses[$subsystem]['status'] = $order[$status['statusCode']];
                if (!empty($status['logLocation'])) {
                    if (!$acl->isPageAccessible($this->getUserName(), $status['logLocation'])) {
                        unset($statuses[$subsystem]);
                    }
                } else {
                    return $response;
                }
            }

            /* Sort on the highest error level after the ACL check */
            $statusCodes = array_map(function ($v) {
                return $v['statusCode'];
            }, array_values($statuses));
            sort($statusCodes);
            $statuses['System'] = [
                'status' => $order[$statusCodes[0] ?? 2]
            ];

            foreach ($statuses as &$status) {
                if (!empty($status['timestamp'])) {
                    $age = time() - $status['timestamp'];

                    if ($age < 0) {
                        /* time jump, do nothing */
                    } elseif ($age < 60) {
                        if ($age == 1) {
                            $status['age'] = sprintf(gettext('%s second ago'), $age);
                        } else {
                            $status['age'] = sprintf(gettext('%s seconds ago'), $age);
                        }
                    } elseif ($age < 60 * 60) {
                         $age = intdiv($age, 60);
                        if ($age == 1) {
                            $status['age'] = sprintf(gettext('%s minute ago'), $age);
                        } else {
                            $status['age'] = sprintf(gettext('%s minutes ago'), $age);
                        }
                    } elseif ($age < 60 * 60 * 24) {
                         $age = intdiv($age, 60 * 60);
                        if ($age == 1) {
                            $status['age'] = sprintf(gettext('%s hour ago'), $age);
                        } else {
                            $status['age'] = sprintf(gettext('%s hours ago'), $age);
                        }
                    } elseif ($age < 60 * 60 * 24 * 7) {
                         $age = intdiv($age, 60 * 60 * 24);
                        if ($age == 1) {
                            $status['age'] = sprintf(gettext('%s day ago'), $age);
                        } else {
                            $status['age'] = sprintf(gettext('%s days ago'), $age);
                        }
                    } elseif ($age < 60 * 60 * 24 * 30) {
                         $age = intdiv($age, 60 * 60 * 24 * 7);
                        if ($age == 1) {
                            $status['age'] = sprintf(gettext('%s week ago'), $age);
                        } else {
                            $status['age'] = sprintf(gettext('%s weeks ago'), $age);
                        }
                    } elseif ($age < 60 * 60 * 24 * 365) {
                         $age = intdiv($age, 60 * 60 * 24 * 30);
                        if ($age == 1) {
                            $status['age'] = sprintf(gettext('%s month ago'), $age);
                        } else {
                            $status['age'] = sprintf(gettext('%s months ago'), $age);
                        }
                    } else {
                         $age = intdiv($age, 60 * 60 * 24 * 365);
                        if ($age == 1) {
                            $status['age'] = sprintf(gettext('%s year ago'), $age);
                        } else {
                            $status['age'] = sprintf(gettext('%s years ago'), $age);
                        }
                    }
                }
            }

            $response = $statuses;
        }

        return $response;
    }

    public function dismissStatusAction()
    {
        $this->sessionClose();

        if ($this->request->isPost() && $this->request->hasPost("subject")) {
            $acl = new ACL();
            $backend = new Backend();
            $subsystem = $this->request->getPost("subject");
            $system = json_decode(trim($backend->configdRun('system status')), true);
            if (array_key_exists($subsystem, $system)) {
                if (!empty($system[$subsystem]['logLocation'])) {
                    $aclCheck = $system[$subsystem]['logLocation'];
                    if (
                        $acl->isPageAccessible($this->getUserName(), $aclCheck) ||
                        !$acl->hasPrivilege($this->getUserName(), 'user-config-readonly')
                    ) {
                        $status = trim($backend->configdRun(sprintf('system dismiss status %s', $subsystem)));
                        if ($status == "OK") {
                            return [
                                "status" => "ok"
                            ];
                        }
                    }
                }
            }
        }

        return ["status" => "failed"];
    }

    public function systemInformationAction()
    {
        $config = Config::getInstance()->object();
        $backend = new Backend();

        $product = json_decode($backend->configdRun('firmware product'), true);
        $current = explode('_', $product['product_version'])[0];
        /* information from changelog, more accurate for production release */
        $from_changelog = strpos($product['product_id'], '-devel') === false &&
            !empty($product['product_latest']) &&
            $product['product_latest'] != $current;

        /* update status from last check, also includes major releases */
        $from_check = !empty($product['product_check']['upgrade_sets']) ||
            !empty($product['product_check']['downgrade_packages']) ||
            !empty($product['product_check']['new_packages']) ||
            !empty($product['product_check']['reinstall_packages']) ||
            !empty($product['product_check']['remove_packages']) ||
            !empty($product['product_check']['upgrade_packages']);

        $response = [
            'name' => $config->system->hostname . '.' . $config->system->domain,
            'versions' => [
                sprintf('%s %s-%s', $product['product_name'], $product['product_version'], $product['product_arch']),
                php_uname('s') . ' ' . php_uname('r'),
                trim($backend->configdRun('system openssl version'))
            ],
            'updates' => ($from_changelog || $from_check)
                ? gettext('Click to view pending updates.')
                : gettext('Click to check for updates.'),
        ];

        return $response;
    }

    public function systemTimeAction()
    {
        $config = Config::getInstance()->object();
        $boottime = json_decode((new Backend())->configdRun('system sysctl values kern.boottime'), true);
        preg_match("/sec = (\d+)/", $boottime['kern.boottime'], $matches);

        $last_change = date("D M j G:i:s T Y", !empty($config->revision->time) ? intval($config->revision->time) : 0);

        $response = [
            'uptime' => $this->formatUptime(time() - $matches[1]),
            'datetime' => date("D M j G:i:s T Y"),
            'config' => $last_change,
        ];

        return $response;
    }

    public function systemResourcesAction()
    {
        $result = [];

        $mem = json_decode((new Backend())->configdpRun('system sysctl values', implode(',', [
            'hw.physmem',
            'vm.stats.vm.v_page_count',
            'vm.stats.vm.v_inactive_count',
            'vm.stats.vm.v_cache_count',
            'vm.stats.vm.v_free_count',
            'kstat.zfs.misc.arcstats.size'
        ])), true);

        if (!empty($mem['vm.stats.vm.v_page_count'])) {
            $pc = $mem['vm.stats.vm.v_page_count'];
            $ic = $mem['vm.stats.vm.v_inactive_count'];
            $cc = $mem['vm.stats.vm.v_cache_count'];
            $fc = $mem['vm.stats.vm.v_free_count'];
            $result['memory']['total'] = $mem['hw.physmem'];
            $result['memory']['total_frmt'] = sprintf('%d', $mem['hw.physmem'] / 1024 / 1024);
            $result['memory']['used'] = round(((($pc - ($ic + $cc + $fc))) / $pc) * $mem['hw.physmem'], 0);
            $result['memory']['used_frmt'] = sprintf('%d', $result['memory']['used'] / 1024 / 1024);
            if (!empty($mem['kstat.zfs.misc.arcstats.size'])) {
                $arc_size = $mem['kstat.zfs.misc.arcstats.size'];
                $result['memory']['arc'] = $arc_size;
                $result['memory']['arc_frmt'] = sprintf('%d', $arc_size / 1024 / 1024);
                $result['memory']['arc_txt'] = sprintf(gettext('ARC size %d MB'), $arc_size / 1024 / 1024);
            }
        } else {
            $result['memory']['used'] = gettext('N/A');
        }

        return $result;
    }

    public function systemDiskAction()
    {
        $result = [];

        $disk_info = json_decode((new Backend())->configdRun('system diag disk'), true);

        if (!empty($disk_info['storage-system-information'])) {
            foreach ($disk_info['storage-system-information']['filesystem'] as $fs) {
                if (!in_array(trim($fs['type']), ['cd9660', 'msdosfs', 'tmpfs', 'ufs', 'zfs'])) {
                    continue;
                }

                $result['devices'][] = [
                    'device' => $fs['name'],
                    'type' => trim($fs['type']),
                    'blocks' => $fs['blocks'],
                    'used' => $fs['used'],
                    'available' => $fs['available'],
                    'used_pct' => $fs['used-percent'],
                    'mountpoint' => $fs['mounted-on'],
                ];
            }
        }

        return $result;
    }

    public function systemMbufAction()
    {
        return json_decode((new Backend())->configdRun('system show mbuf'), true);
    }

    public function systemSwapAction()
    {
        return json_decode((new Backend())->configdRun('system show swapinfo'), true);
    }

    public function systemTemperatureAction()
    {
        $result = [];

        foreach (explode("\n", (new Backend())->configdRun('system temp')) as $sysctl) {
            $parts = explode('=', $);
            if (count($parts) >= 2) {
                $tempItem = array();
                $tempItem['device'] = $parts[0];
                $tempItem['device_seq'] = filter_var($tempItem['device'], FILTER_SANITIZE_NUMBER_INT);
                $tempItem['temperature'] = trim(str_replace('C', '', $parts[1]));
                $tempItem['type'] = strpos($tempItem['device'], 'hw.acpi') !== false ? "zone" : "core";
                $tempItem['type_translated'] = $tempItem['type'] == "zone" ? gettext("Zone") : gettext("Core");
                $result[] = $tempItem;
            }
        }

        return $result;
    }
}
