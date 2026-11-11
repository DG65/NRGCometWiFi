# NRG-Stack CometWiFi

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module version](https://img.shields.io/badge/Module_Version-0.20.0-blue)
![Symcon version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGCometWiFi/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGCometWiFi/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Integrates **Eurotronic Comet WiFi** radiator thermostats into Symcon — without a detour via the
manufacturer's cloud, yet without losing the manufacturer's app.

Part of the **NRG-Stack**. Available in German and English ([German README](README.md)); the
language follows the Symcon setting.

> ⚠️ **Pure reverse engineering.** Eurotronic does not document the protocol. This module relies
> on our own captures and on knowledge from forums. A firmware update from the manufacturer can
> break it at any time. What exactly is considered confirmed and what is not is recorded in
> [`.docs/protokoll.md`](.docs/protokoll.md) (in German).

## Included modules

| Module | Purpose |
|---|---|
| **Comet WiFi Thermostat** | One instance per thermostat: current/target temperature, battery, signal strength, reachability |
| **Comet WiFi Configurator** | Listens passively and lists all thermostats found, so you can create them with one click |
| **Comet WiFi Tile** | Overview of all thermostats on one tile |
| **Comet WiFi Room Tile** | A single thermostat, large and operable on a tile |
| **Comet WiFi Room** | Several thermostats as one jointly operable instance |

## Prerequisite: a local MQTT broker

The thermostats talk **exclusively** to `mqtt.eurotronic.io` — the broker cannot be changed in
the device. For Symcon to see them, that name must point to your own broker in your own
network. The module itself does **not** set this up; it assumes a working broker.

### Setup in three steps

**1. Read out the credentials.** All Comet WiFi devices of one account use the same MQTT
credentials. They appear in plain text in the connection setup because port 1883 is
unencrypted — a capture is enough:

```bash
sudo tcpdump -i <interface> -n -w comet.pcap port 1883
# Briefly cut the power to a thermostat (battery out/in), then Ctrl+C
tshark -r comet.pcap -Y "mqtt.msgtype==1" -V | grep -E "Client ID|User Name|Password"
```

**2. Set up your own broker** (e.g. Mosquitto) with exactly these credentials in the password
file. For the manufacturer's app to keep working, you need **one bridge per device** to the real
cloud, each with the real client ID:

```
connection eurotronic-bridge-<name>
address mqtt.eurotronic.io:1883
remote_username <USER>
remote_password <PASSWORD>
remote_clientid da16x02<USER><last-3-MAC-bytes>
try_private false
cleansession true
bridge_protocol_version mqttv311
topic 02/<USER>/<MAC>/V/# out 0
topic 02/<USER>/<MAC>/S/# in 0
topic 02/FFFFFFFF/# in 0
```

The **directions matter**: without them the bridge also carries the commands sent by Symcon out
to the cloud, and the app and Symcon overwrite each other.

One shared bridge for all devices is **not** enough — Eurotronic's backend apparently ties the
online status to the client ID of the individual device. With only one bridge, exactly one
device stays online in the app and the rest appear offline.

On the broker machine itself, the real cloud addresses must be in `/etc/hosts`, otherwise the
DNS redirection points the bridge back at itself:

```
172.104.138.107   mqtt.eurotronic.io mqtt1.eurotronic.io mqtt2.eurotronic.io mqtt3.eurotronic.io
```

**3. Redirect DNS.** In Pi-hole, AdGuard, dnsmasq or Unbound, add one local entry each for
`mqtt.eurotronic.io`, `mqtt1`, `mqtt2` and `mqtt3` pointing to the IP of your own broker. Then
cut the power to the thermostats once so that they resolve the name again.

## Setup in Symcon

1. Create an **MQTT Client** (core module), point it at your own broker, using the same
   credentials. The client ID must **not** start with `da16x02…`, otherwise the broker kicks out
   the thermostat of the same name.
2. Create a **Comet WiFi Configurator** as a child of this MQTT Client. It collects the devices
   as soon as they send something.
3. In the configurator, click "Create" for each thermostat.

Devices only appear once they send something on their own — this can take minutes to hours. The
configurator deliberately queries **nothing** (see the battery note).

## 🔋 Battery — please read

From the ioBroker community, **battery failure within about two weeks** is reported after a
comparable redirection. The cause is not proven; it seems likely that frequent querying wakes
the devices from their power-saving mode.

This module therefore proceeds defensively:

- Active polling is **switched off by default.** The thermostats send on their own.
- If it is switched on, a minimum interval of 15 minutes applies, and the polls of the individual
  devices are offset against each other.
- It does **not** poll when Symcon starts or when the form is opened.
- The configurator sends nothing at all.

The battery level is kept as a variable — if you archive it, you will see for yourself after a
few weeks whether your own setting is acceptable.

## What is decoded — and what is not

Only registers whose meaning is **confirmed on the device** are kept as variables. For
everything else there are raw variables on request, but no invented interpretation.

**Confirmed:** current and target temperature, battery level, signal strength, the options
(operating mode, key lock, automatic daylight saving time, display rotation), temperature
offset, weekly schedule, holiday, device clock and device information (model, firmware, IP
address, Wi-Fi, group).

**Still open:**

- **Open-window detection** (`A5`) — values seen, meaning not clarified
- **Temperature offset** (`A2`) — negative values unchecked
- **Valve position** — apparently does not exist on this model

A button in the form fetches the weekly schedule and the holiday; they can only be **changed** by
script (`CWIFI_SetScheduleDay`, `CWIFI_SetHoliday`, `CWIFI_ClearHoliday`), not in the form.

If you switch on raw data capture and archive it for a few weeks, you provide exactly the
material needed to decode the open registers after the fact. The detailed status is kept in
[`.docs/protokoll.md`](.docs/protokoll.md).

## Known limitations

- **A setpoint that has been set is not permanent.** The weekly schedule keeps running in the
  device and overwrites it at the next switching point — and so does the manufacturer's app.
  This is not a fault of the module but the behaviour of the device.
- **Reachability is coarse.** The device reports with a keepalive of 600 s; a failure is noticed
  after about 15 minutes at the earliest.
- **No internet, no app and no time.** The cloud bridge needs internet. If it is permanently
  gone, the configurator can optionally take over time synchronisation itself.

## License

[PolyForm Noncommercial License 1.0.0](LICENSE) — free for private and non-commercial use,
commercial use requires a license (contact: DG65). Donations welcome.
