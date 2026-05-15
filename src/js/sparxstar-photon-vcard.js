/**
 * SPARXSTAR Photon VCard — front-end runtime.
 *
 * @file        sparxstar-photon-vcard.js
 * @package     sparxstar-photon-vcard
 * @copyright   2025 Starisian Technologies. All rights reserved.
 * @license     Starisian Technologies Proprietary
 * @author      Starisian Technologies (Max Barrett) <support@starisian.com>
 *
 * Bootstrapped by WordPress via wp_enqueue_script. Card data is injected
 * server-side via wp_add_inline_script as:
 *   window.SPX_PHOTON_VCARD_USERS   — map of uid → sanitized card payload
 *   window.SPX_PHOTON_VCARD_DEFAULT — uid of the first registered card owner
 *
 * This script runs as an IIFE with no external dependencies (except the optional
 * QR library loaded as a separate script handle). It must never import from CDNs
 * or make network requests of its own.
 *
 * Features:
 *  – Motion triggers: single face-down flip (deviceorientation) OR shake (devicemotion)
 *  – Keyboard trigger: Shift+V
 *  – Long-press touch trigger
 *  – [spx_photon_vcard] shortcode button support (data-spx-vcard-trigger)
 *  – Business-card-styled overlay with logo/photo, full contact details
 *  – QR code (vCard data encoded via qrcode.min.js)
 *  – Fullscreen QR tap mode: tap QR to fill viewport (black on white)
 *  – WhatsApp share button: wa.me deep-link when a WhatsApp channel is configured
 *  – Save Contact (.vcf download)
 *  – Web Share API URL share
 *  – Send to Device: Web Share API with .vcf file (fallback when no WhatsApp number)
 *  – Wake lock while card is visible
 *  – WCAG 2.1 focus trap + keyboard navigation
 *  – Respects prefers-reduced-motion
 *  – Fails silently: all errors are caught; page operation is never blocked
 */
(function (window, document) {
  "use strict";

  if (window.__SPX_PHOTON_CARD_LOADED__) return;
  window.__SPX_PHOTON_CARD_LOADED__ = true;

  const usersMap =
    window.SPX_PHOTON_VCARD_USERS &&
    typeof window.SPX_PHOTON_VCARD_USERS === "object"
      ? window.SPX_PHOTON_VCARD_USERS
      : {};

  const defaultUid = Number(window.SPX_PHOTON_VCARD_DEFAULT) || 0;

  class SpxPhotonVCard {
    constructor() {
      this.config = {
        threshold: 145,
        cooldown: 5000,
        stabilize: 150,
        longPress: 800,
        sensorFps: 10,
        sensorAutoDisable: 30000,
        gammaThreshold: 25,
        permissionTimeout: 2000,
        shakeThreshold: 324,
        shakeRequired: 3,
        shakeWindow: 1200,
        motionThrottle: 120,
      };

      this.state = {
        lastCloseTime: 0,
        isFaceDown: false,
        isActive: false,
        sensorBound: false,
        scrollPos: 0,
        shakeCount: 0,
        firstShakeTime: 0,
        lastMagSq: 0,
        lastMotionTime: 0,
      };

      this.timers = {
        sensorTick: 0,
        stabilizer: null,
        longPress: null,
        sensorAutoDisable: null,
      };

      this.boundOrientationHandler = null;
      this.boundMotionHandler = null;
      this.wakeLock = null;
      this.lastFocus = null;
      this._qrFsClose = null;
      this._onVisChange = null;
      this.activeUid = defaultUid;

      this.init();
    }

    init() {
      const reducedMotion =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;

      const defaultData = usersMap[defaultUid] || {};

      if (!reducedMotion && !defaultData.noSensor && !this.isLowEndDevice()) {
        this.setupMotion();
      }

      window.addEventListener("pagehide", () => this.releaseWakeLock());

      this.setupFallbacks();
      this.setupShortcodeTriggers();

      if (!document.querySelector("[data-spx-vcard-trigger]")) {
        this.renderOpenButton();
      }
    }

    get d() {
      return usersMap[this.activeUid] || {};
    }

    reportRuntimeError(error, context, extra = {}) {
      if (!error) return;

      const message = String(error.message || error);
      const eventType = extra.eventType || "js_error";
      const contextData = { ...extra };
      delete contextData.eventType;
      const payload = {
        event_type: eventType,
        timestamp: Math.floor(Date.now() / 1000),
        url: window.location.pathname,
        error: {
          message,
          source: "sparxstar-photon-vcard",
          line: Number(error.lineNumber || 0),
          column: Number(error.columnNumber || 0),
          stack: error.stack ? String(error.stack) : null,
        },
        context: {
          plugin: "sparxstar-photon-vcard",
          location: context,
          ...contextData,
        },
      };

      try {
        if (window.SIRUS && typeof window.SIRUS.send === "function") {
          window.SIRUS.send(payload);
          return;
        }

        if (
          window.SPARXSTAR &&
          typeof window.SPARXSTAR.logRecorderEvent === "function"
        ) {
          window.SPARXSTAR.logRecorderEvent(payload);
        }
      } catch {
        // Never throw while attempting telemetry.
      }
    }

    isLowEndDevice() {
      const hc = navigator.hardwareConcurrency;
      const dm = navigator.deviceMemory;

      return (
        (typeof hc === "number" && hc <= 4) ||
        (typeof dm === "number" && dm <= 2)
      );
    }

    storage(key, val = null) {
      try {
        if (val !== null) {
          localStorage.setItem(key, String(val));
          return null;
        }

        return localStorage.getItem(key);
      } catch (error) {
        this.reportRuntimeError(error, "storage");
        return null;
      }
    }

    setupMotion() {
      const isEnabled = this.storage("spx_photon_motion_enabled") === "true";

      if (isEnabled) {
        this.requestSensorAccess();
      } else {
        this.renderSetupButton();
      }
    }

    renderSetupButton() {
      if (this.state.sensorBound) return;
      if (document.getElementById("spax-photon-sensor-grant")) return;

      const btn = document.createElement("button");
      btn.id = "spax-photon-sensor-grant";
      btn.type = "button";
      btn.textContent = "Enable Motion Trigger";
      btn.setAttribute(
        "aria-label",
        "Enable motion-based card trigger (flip or shake)",
      );

      btn.addEventListener(
        "click",
        () => {
          this.requestSensorAccess(true);
          btn.remove();
        },
        { passive: true },
      );

      document.body.appendChild(btn);
    }

    renderOpenButton() {
      if (document.getElementById("spax-photon-open-btn")) return;

      const btn = document.createElement("button");
      btn.id = "spax-photon-open-btn";
      btn.type = "button";
      btn.textContent = "View Business Card";
      btn.setAttribute("aria-label", "Open digital business card");
      btn.dataset.spxVcardUid = String(defaultUid);

      btn.addEventListener(
        "click",
        () => this.openCard("button", defaultUid),
        { passive: true },
      );

      document.body.appendChild(btn);
    }

    setupShortcodeTriggers() {
      const triggers = document.querySelectorAll("[data-spx-vcard-trigger]");

      triggers.forEach((el) => {
        const uid = Number(el.dataset.spxVcardUid) || defaultUid;

        el.addEventListener("click", () => this.openCard("shortcode", uid), {
          passive: true,
        });
      });
    }

    async requestSensorAccess(fromUserGesture = false) {
      if (this.state.sensorBound) return;

      try {
        const needsOrientPerm =
          typeof DeviceOrientationEvent !== "undefined" &&
          typeof DeviceOrientationEvent.requestPermission === "function";

        const needsMotionPerm =
          typeof DeviceMotionEvent !== "undefined" &&
          typeof DeviceMotionEvent.requestPermission === "function";

        if (needsOrientPerm || needsMotionPerm) {
          // On iOS, requestPermission() must be called from a direct user
          // gesture.  When invoked automatically (e.g. at init or after card
          // close), skip the permission prompt and show the setup button so
          // the user can grant access themselves.
          if (!fromUserGesture) {
            this.renderSetupButton();
            return;
          }

          const timeout = setTimeout(
            () => this.renderSetupButton(),
            this.config.permissionTimeout,
          );

          let orientGranted = !needsOrientPerm;
          let motionGranted = !needsMotionPerm;

          if (needsOrientPerm) {
            const r = await DeviceOrientationEvent.requestPermission();
            orientGranted = r === "granted";
          }

          if (needsMotionPerm) {
            const r = await DeviceMotionEvent.requestPermission();
            motionGranted = r === "granted";
          }

          clearTimeout(timeout);

          if (orientGranted || motionGranted) {
            this.enableSensors(orientGranted, motionGranted);
          } else {
            this.renderSetupButton();
          }

          return;
        }

        this.enableSensors(true, true);
      } catch (error) {
        // Permission request failed (e.g. called outside a user gesture).
        // Clear the persisted flag so the setup button re-appears on next load.
        this.storage("spx_photon_motion_enabled", "false");
        this.renderSetupButton();
        this.reportRuntimeError(error, "requestSensorAccess");
      }
    }

    enableSensors(orientGranted = true, motionGranted = true) {
      if (this.state.sensorBound) return;

      if (orientGranted) {
        this.boundOrientationHandler = this.handleOrientation.bind(this);
        window.addEventListener(
          "deviceorientation",
          this.boundOrientationHandler,
          { passive: true },
        );
      }

      if (motionGranted) {
        this.boundMotionHandler = this.handleMotion.bind(this);
        window.addEventListener("devicemotion", this.boundMotionHandler, {
          passive: true,
        });
      }

      if (!orientGranted && !motionGranted) return;

      this.state.sensorBound = true;
      this.storage("spx_photon_motion_enabled", "true");

      const grantBtn = document.getElementById("spax-photon-sensor-grant");
      if (grantBtn) grantBtn.remove();

      this.resetSensorAutoDisable();
      this.vibrate(40);
    }

    disableSensors(clearStorageFlag = true) {
      if (!this.state.sensorBound) return;

      window.removeEventListener(
        "deviceorientation",
        this.boundOrientationHandler,
      );

      if (this.boundMotionHandler) {
        window.removeEventListener("devicemotion", this.boundMotionHandler);
      }

      this.state.sensorBound = false;

      if (clearStorageFlag) {
        this.storage("spx_photon_motion_enabled", "false");
      }

      if (this.timers.sensorAutoDisable) {
        clearTimeout(this.timers.sensorAutoDisable);
        this.timers.sensorAutoDisable = null;
      }
    }

    resetSensorAutoDisable() {
      if (this.timers.sensorAutoDisable) {
        clearTimeout(this.timers.sensorAutoDisable);
      }

      this.timers.sensorAutoDisable = setTimeout(() => {
        this.disableSensors(false);
      }, this.config.sensorAutoDisable);
    }

    handleOrientation(event) {
      if (this.state.isActive) return;
      if (!this.state.sensorBound) return;

      this.resetSensorAutoDisable();

      const now = Date.now();
      const minDelta = Math.floor(1000 / this.config.sensorFps);

      if (now - this.timers.sensorTick < minDelta) return;

      this.timers.sensorTick = now;

      const beta = Math.abs(event.beta || 0);
      const gamma = Math.abs(event.gamma || 0);

      const isFlat =
        beta > this.config.threshold && gamma < this.config.gammaThreshold;

      if (isFlat && !this.state.isFaceDown) {
        if (!this.timers.stabilizer) {
          this.timers.stabilizer = setTimeout(() => {
            this.state.isFaceDown = true;
            this.openCard("flip");
            this.timers.stabilizer = null;
          }, this.config.stabilize);
        }

        return;
      }

      if (!isFlat) {
        if (this.timers.stabilizer) {
          clearTimeout(this.timers.stabilizer);
          this.timers.stabilizer = null;
        }

        this.state.isFaceDown = false;
      }
    }

    handleMotion(event) {
      if (this.state.isActive) return;
      if (!this.state.sensorBound) return;

      const now = Date.now();

      if (now - this.state.lastMotionTime < this.config.motionThrottle) return;

      this.state.lastMotionTime = now;
      this.resetSensorAutoDisable();

      const acc = event.accelerationIncludingGravity;
      if (!acc) return;

      const magSq = (acc.x || 0) ** 2 + (acc.y || 0) ** 2 + (acc.z || 0) ** 2;
      const delta = Math.abs(magSq - this.state.lastMagSq);

      this.state.lastMagSq = magSq;

      if (delta <= this.config.shakeThreshold) return;

      if (
        !this.state.firstShakeTime ||
        now - this.state.firstShakeTime > this.config.shakeWindow
      ) {
        this.state.shakeCount = 1;
        this.state.firstShakeTime = now;
        return;
      }

      this.state.shakeCount++;

      if (this.state.shakeCount >= this.config.shakeRequired) {
        this.state.shakeCount = 0;
        this.state.firstShakeTime = 0;
        this.openCard("shake");
      }
    }

    setupFallbacks() {
      document.addEventListener("keydown", (e) => {
        if (this.state.isActive) return;

        if (e.shiftKey && (e.key === "V" || e.key === "v")) {
          this.openCard("keyboard");
        }
      });

      let startY = 0;

      const startPress = () => {
        if (this.state.isActive) return;

        startY = window.scrollY;

        this.timers.longPress = setTimeout(() => {
          if (Math.abs(window.scrollY - startY) < 10) {
            this.openCard("touch");
          }
        }, this.config.longPress);
      };

      const cancelPress = () => {
        if (this.timers.longPress) {
          clearTimeout(this.timers.longPress);
          this.timers.longPress = null;
        }
      };

      document.addEventListener("touchstart", startPress, { passive: true });
      document.addEventListener("touchend", cancelPress);
      document.addEventListener("touchmove", cancelPress, { passive: true });
    }

    openCard(triggerMethod, uid) {
      if (Date.now() - this.state.lastCloseTime < this.config.cooldown) return;

      if (
        this.state.isActive ||
        document.getElementById("spax-photon-card-overlay")
      ) {
        return;
      }

      this.activeUid =
        uid !== undefined && usersMap[uid] ? Number(uid) : defaultUid;

      this.lastFocus = document.activeElement;
      this.state.isActive = true;

      this.disableSensors(false);

      const openBtn = document.getElementById("spax-photon-open-btn");
      if (openBtn) openBtn.hidden = true;

      this.state.scrollPos = window.scrollY;

      document.body.style.top = `-${this.state.scrollPos}px`;
      document.body.classList.add("spax-photon-card-active");

      document.dispatchEvent(
        new CustomEvent("spx-photon-card-event", {
          detail: {
            type: "open",
            method: triggerMethod,
            timestamp: Date.now(),
          },
        }),
      );

      this.vibrate([80, 50, 80]);
      this.injectOverlay();
      this.keepScreenAwake();
    }

    injectOverlay() {
      const overlay = document.createElement("div");

      overlay.id = "spax-photon-card-overlay";
      overlay.setAttribute("role", "dialog");
      overlay.setAttribute("aria-modal", "true");
      overlay.setAttribute("aria-label", "Digital Business Card");

      const name = this.esc(this.d.name || "");
      const title = this.esc(this.d.title || "");
      const company = this.esc(this.d.company || "");

      // First phone (for the prominent link on the card face)
      const phones = Array.isArray(this.d.phones) ? this.d.phones : [];
      const toTelHrefValue = (value) => {
        const raw = String(value || "").trim();
        if (!raw) return "";
        const extMatch = raw.match(/(?:ext\.?|x)\s*[:.]?\s*(\d+)$/i);
        const extension = extMatch ? extMatch[1] : "";
        const mainPart = extMatch ? raw.slice(0, extMatch.index).trim() : raw;
        const hasLeadingPlus = /^\s*\+/.test(mainPart);
        const digits = mainPart.replace(/\D/g, "");
        if (!digits) return "";
        return `${hasLeadingPlus ? "+" : ""}${digits}${extension ? `;ext=${extension}` : ""}`;
      };

      // SVG icons
      const iconPhone = `<svg class="spx-phone-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>`;

      let primaryPhoneHtml = "";
      if (phones.length) {
        const firstPhone = phones[0];
        const telHref = toTelHrefValue(firstPhone.number);
        if (telHref) {
          primaryPhoneHtml = `<a class="spx-phone-link" href="tel:${this.escAttr(telHref)}" aria-label="Call ${this.escAttr(firstPhone.number)}">${iconPhone}<span class="spx-phone-sep" aria-hidden="true"></span>${this.esc(firstPhone.number)}</a>`;
        }
      }

      const photoSrc = this.d.photo ? this.safeURL(this.d.photo) : "";

      // Initials fallback: up to 2 characters from first + last name token.
      const initials =
        (this.d.name || "")
          .trim()
          .split(/\s+/)
          .filter((w) => w.length > 0)
          .slice(0, 2)
          .map((w) => w.charAt(0))
          .join("")
          .toUpperCase() || "?";

      const canSendFile = this._canSendFile();
      const canShare = this._canShare();

      const iconSave = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`;
      const iconShare = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>`;
      const iconSend = `<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>`;
      const iconWhatsApp = `<svg viewBox="0 0 24 24" aria-hidden="true" class="spx-wa-icon"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.867-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.521.149-.172.198-.296.298-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>`;

      const waUrl = this._whatsappUrl();
      const sendLabel = this.esc(this._sendButtonLabel());

      overlay.innerHTML = `
<button type="button" class="spax-photon-close" id="spax-photon-close-btn" aria-label="Close business card">&#x2715;</button>

<div class="spx-vcard-page-wrapper">
  <div class="spx-card-wrapper">
    <div class="spx-card-content">
      ${
        photoSrc
          ? `<img src="${this.escAttr(photoSrc)}" class="spx-user-photo" alt="${this.escAttr(this.d.name || "")}" width="90" height="90" loading="eager" decoding="async">`
          : `<div class="spx-user-photo spx-user-initials" role="img" aria-label="${this.escAttr(this.d.name ? this.d.name + " initials" : "User initials")}">${this.esc(initials)}</div>`
      }
      ${name ? `<p class="spx-name">${name}</p>` : ""}
      ${title ? `<p class="spx-title">${title}</p>` : ""}
      ${company ? `<p class="spx-company">${company}</p>` : ""}
      ${primaryPhoneHtml}
      <div id="spax-photon-qr" class="spx-qr-code" role="button" tabindex="0" aria-label="Scan to save contact. Tap to expand QR code fullscreen."></div>
      <div class="spx-action-bar" role="group" aria-label="Business card actions">
        <div class="spx-action-grid">
          ${
            waUrl
              ? `<a class="spx-action-item spx-action-item--whatsapp" href="${this.escAttr(waUrl)}" target="_blank" rel="noopener noreferrer" id="spax-photon-wa-btn" aria-label="Chat on WhatsApp">${iconWhatsApp}</a>`
              : canSendFile
                ? `<button type="button" class="spx-action-item" id="spax-photon-send-btn" aria-label="${sendLabel}">${iconSend}</button>`
                : ""
          }
          ${
            canShare
              ? `<button type="button" class="spx-action-item" id="spax-photon-share-btn" aria-label="Share Link">${iconShare}</button>`
              : ""
          }
          <button type="button" class="spx-action-item" id="spax-photon-save-btn" aria-label="Save Contact">${iconSave}</button>
        </div>
      </div>
    </div>
  </div>
</div>
`;

      overlay.setAttribute("tabindex", "-1");
      document.body.appendChild(overlay);
      this.setupOverlayFocusTrap(overlay);
      this.attachOverlayActions();
      this.renderVCardQR();

      const closeButton = overlay.querySelector("#spax-photon-close-btn");
      if (closeButton && typeof closeButton.focus === "function") {
        closeButton.focus({ preventScroll: true });
      } else if (typeof overlay.focus === "function") {
        overlay.focus({ preventScroll: true });
      }
    }

    renderPrimaryAction() {
      const waUrl = this._whatsappUrl();

      if (waUrl) {
        return `
<a
  class="spx-action-item spx-action-item-whatsapp"
  href="${this.escAttr(waUrl)}"
  target="_blank"
  rel="noopener noreferrer"
  id="spax-photon-wa-btn"
  aria-label="Chat on WhatsApp">
  ${this.iconWhatsApp()}
</a>
`;
      }

      if (!this._canSendFile()) return "";

      return `
<button
  type="button"
  class="spx-action-item"
  id="spax-photon-send-btn"
  aria-label="${this.esc(this._sendButtonLabel())}">
  ${this.iconSend()}
</button>
`;
    }

    renderShareAction() {
      if (!this._canShare()) return "";

      return `
<button
  type="button"
  class="spx-action-item"
  id="spax-photon-share-btn"
  aria-label="Share Link">
  ${this.iconShare()}
</button>
`;
    }

    renderSaveAction() {
      return `
<button
  type="button"
  class="spx-action-item"
  id="spax-photon-save-btn"
  aria-label="Save Contact">
  ${this.iconSave()}
</button>
`;
    }

    setupOverlayFocusTrap(overlay) {
      const getFocusable = () =>
        Array.from(
          overlay.querySelectorAll(
            'button, a, [tabindex]:not([tabindex="-1"])',
          ),
        ).filter((el) => el instanceof HTMLElement && !el.hidden);

      overlay.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
          this.closeCard();
          return;
        }

        if (e.key !== "Tab") return;

        const focusable = getFocusable();
        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (e.shiftKey) {
          if (document.activeElement === first) {
            e.preventDefault();
            last.focus();
          }

          return;
        }

        if (document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      });
    }

    attachOverlayActions() {
      const sendBtn = document.getElementById("spax-photon-send-btn");
      const shareBtn = document.getElementById("spax-photon-share-btn");
      const saveBtn = document.getElementById("spax-photon-save-btn");
      const closeBtn = document.getElementById("spax-photon-close-btn");
      const overlay = document.getElementById("spax-photon-card-overlay");
      const qrEl = document.getElementById("spax-photon-qr");

      if (!overlay) return;

      if (sendBtn) {
        sendBtn.addEventListener("click", () => this.sendToDevice(), {
          passive: true,
        });
      }

      if (shareBtn) {
        shareBtn.addEventListener("click", () => this.shareCard(), {
          passive: true,
        });
      }

      if (saveBtn) {
        saveBtn.addEventListener("click", () => this.downloadVCard(), {
          passive: true,
        });
      }

      if (closeBtn) {
        closeBtn.addEventListener("click", () => this.closeCard(), {
          passive: true,
        });
      }

      if (qrEl) {
        qrEl.addEventListener("click", () => this.openQRFullscreen(), {
          passive: true,
        });

        qrEl.addEventListener("keydown", (e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            this.openQRFullscreen();
          }
        });
      }

      // Hide broken profile image without an inline onerror (CSP-safe).
      const photoEl = overlay.querySelector(".spx-user-photo");
      if (photoEl) {
        photoEl.addEventListener(
          "error",
          () => {
            const fallback = document.createElement("div");
            fallback.className = "spx-user-initials";
            fallback.setAttribute("role", "img");
            fallback.setAttribute(
              "aria-label",
              this.d.name ? `${this.d.name} initials` : "User initials",
            );
            fallback.textContent = this.getInitials();

            photoEl.replaceWith(fallback);
          },
          { once: true },
        );
      }

      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) this.closeCard();
      });
    }

    closeCard() {
      const overlay = document.getElementById("spax-photon-card-overlay");
      if (!overlay) return;

      if (this._qrFsClose) {
        this._qrFsClose({ restoreFocus: false });
      }

      document.body.classList.remove("spax-photon-card-active");
      document.body.style.top = "";

      window.scrollTo(0, this.state.scrollPos);

      this.state.isActive = false;
      this.state.lastCloseTime = Date.now();
      this.state.isFaceDown = false;

      if (this.timers.stabilizer) {
        clearTimeout(this.timers.stabilizer);
        this.timers.stabilizer = null;
      }

      if (this.timers.longPress) {
        clearTimeout(this.timers.longPress);
        this.timers.longPress = null;
      }

      document.dispatchEvent(
        new CustomEvent("spx-photon-card-event", {
          detail: {
            type: "close",
            method: null,
            timestamp: Date.now(),
          },
        }),
      );

      overlay.remove();

      const openBtn = document.getElementById("spax-photon-open-btn");
      if (openBtn) openBtn.hidden = false;

      if (this.lastFocus) {
        this.lastFocus.focus();
        this.lastFocus = null;
      }

      const reducedMotion =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;

      const defaultData = usersMap[defaultUid] || {};

      if (!reducedMotion && !defaultData.noSensor && !this.isLowEndDevice()) {
        this.requestSensorAccess();
      }

      this.releaseWakeLock();
    }

    shareCard() {
      if (!this._canShare()) return;

      navigator
        .share({
          title: this.d.name || "Business Card",
          text: `${this.d.name || ""}${
            this.d.title ? ` — ${this.d.title}` : ""
          }`.trim(),
          url: window.location.href,
        })
        .catch((error) => {
          if (error && error.name !== "AbortError") {
            this.reportRuntimeError(error, "shareCard");
          }
        });
    }

    async sendToDevice() {
      const vcard = this.generateVCard();
      const blob = new Blob([vcard], { type: "text/vcard" });
      const fname = (this.d.name || "contact").replace(/[^\w-]+/g, "_");
      const file = new File([blob], `${fname}.vcf`, { type: "text/vcard" });

      try {
        await navigator.share({
          files: [file],
          title: this.d.name || "Contact Card",
        });
      } catch (error) {
        if (error && error.name !== "AbortError") {
          this.reportRuntimeError(error, "sendToDevice");
          this.downloadVCard();
        }
      }
    }

    generateVCard() {
      const clean = (s) =>
        String(s || "")
          .replace(/[\r\n]/g, " ")
          .trim();

      const ve = (s) => this.vcardEsc(s);

      const name = clean(this.d.name);
      const title = clean(this.d.title);
      const company = clean(this.d.company);
      const email = String(this.d.email || "")
        .replace(/\s/g, "")
        .trim();
      const website = String(this.d.website || "").trim();
      const photo = String(this.d.photo || "").trim();
      const phones = Array.isArray(this.d.phones) ? this.d.phones : [];
      const channels = Array.isArray(this.d.channels) ? this.d.channels : [];
      const social = Array.isArray(this.d.social) ? this.d.social : [];
      const addr = this.d.address || {};

      const lines = ["BEGIN:VCARD", "VERSION:3.0", `FN:${ve(name)}`];

      const parts = name.split(" ");

      if (parts.length === 2) {
        lines.push(`N:${ve(parts[1])};${ve(parts[0])};;;`);
      }

      if (title) lines.push(`TITLE:${ve(title)}`);
      if (company) lines.push(`ORG:${ve(company)}`);

      phones.forEach((p) => {
        if (!p || !p.number) return;

        const phoneValue = ve(String(p.number).replace(/\s+/g, ""));

        lines.push(
          `TEL;TYPE=${String(p.type || "VOICE").toUpperCase()}:${phoneValue}`,
        );
      });

      channels.forEach((c) => {
        const key = String(c.key || "");
        const cleanVal = ve(String(c.val || "").replace(/\s+/g, ""));

        if (!cleanVal) return;

        switch (key) {
          case "whatsapp":
            lines.push(`TEL;TYPE=CELL,VOICE:${cleanVal}`);
            lines.push(`X-WHATSAPP:${cleanVal}`);
            break;

          case "telegram":
            lines.push(`X-TELEGRAM:${cleanVal}`);
            break;

          case "signal":
            lines.push(`X-SIGNAL:${cleanVal}`);
            break;

          case "wechat":
            lines.push(`X-WECHAT:${cleanVal}`);
            break;

          case "viber":
            lines.push(`X-VIBER:${cleanVal}`);
            break;

          default:
            lines.push(
              `X-${key.toUpperCase().replace(/[^A-Z0-9]/g, "")}:${cleanVal}`,
            );
        }
      });

      if (email) lines.push(`EMAIL:${ve(email)}`);
      if (website) lines.push(`URL:${ve(website)}`);

      const s1 = ve(String(addr.street1 || "").trim());
      const s2 = ve(String(addr.street2 || "").trim());
      const ct = ve(String(addr.city || "").trim());
      const st = ve(String(addr.state || "").trim());
      const pc = ve(String(addr.postcode || "").trim());
      const co = ve(String(addr.country || "").trim());

      if (s1 || s2 || ct || st || pc || co) {
        lines.push(`ADR;TYPE=WORK:;${s2};${s1};${ct};${st};${pc};${co}`);
      }

      const socialMap = {
        amazon_music: "AMAZON-MUSIC",
        apple_music: "APPLE-MUSIC",
        audiomack: "AUDIOMACK",
        baidu_tieba: "BAIDU-TIEBA",
        bandcamp: "BANDCAMP",
        behance: "BEHANCE",
        bereal: "BEREAL",
        bilibili: "BILIBILI",
        bluesky: "BLUESKY",
        caffeine: "CAFFEINE",
        deezer: "DEEZER",
        discord: "DISCORD",
        douyin: "DOUYIN",
        dribbble: "DRIBBBLE",
        facebook: "FACEBOOK",
        flickr: "FLICKR",
        github: "GITHUB",
        instagram: "INSTAGRAM",
        kick: "KICK",
        kuaishou: "KUAISHOU",
        lemmy: "LEMMY",
        line: "LINE",
        linkedin: "LINKEDIN",
        mastodon: "MASTODON",
        medium: "MEDIUM",
        pandora: "PANDORA",
        pinterest: "PINTEREST",
        pixiv: "PIXIV",
        qq: "QQ",
        quora: "QUORA",
        reddit: "REDDIT",
        rumble: "RUMBLE",
        snapchat: "SNAPCHAT",
        soundcloud: "SOUNDCLOUD",
        spotify: "SPOTIFY",
        stack_overflow: "STACKOVERFLOW",
        telegram: "TELEGRAM",
        threads: "THREADS",
        tidal: "TIDAL",
        tiktok: "TIKTOK",
        truth_social: "TRUTH-SOCIAL",
        tumblr: "TUMBLR",
        twitch: "TWITCH",
        viber: "VIBER",
        vk: "VK",
        vsco: "VSCO",
        whatsapp: "WHATSAPP",
        wechat: "WECHAT",
        weibo: "WEIBO",
        xiaohongshu: "XIAOHONGSHU",
        x: "X",
        youtube: "YOUTUBE",
        youtube_music: "YOUTUBE-MUSIC",
        zhihu: "ZHIHU",
      };

      social.forEach((s) => {
        if (!s || !s.val) return;

        const safeVal = this.safeURL(s.val);
        if (!safeVal) return;

        const rawKey = String(clean(s.key) || "")
          .trim()
          .toLowerCase();

        const socialType =
          socialMap[rawKey] ||
          rawKey
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, "-")
            .replace(/^-+|-+$/g, "") ||
          "SOCIAL";

        lines.push(`X-SOCIALPROFILE;TYPE=${socialType}:${ve(safeVal)}`);
        lines.push(`URL:${ve(safeVal)}`);
      });

      if (photo) {
        const safePhoto = this.safeURL(photo);
        if (safePhoto) lines.push(`PHOTO;VALUE=URI:${ve(safePhoto)}`);
      }

      lines.push(
        `REV:${new Date().toISOString().replace(/[-:]/g, "").split(".")[0]}Z`,
      );

      lines.push("END:VCARD");

      return lines.join("\r\n");
    }

    downloadVCard() {
      const vcard = this.generateVCard();
      const blob = new Blob([vcard], { type: "text/vcard;charset=utf-8" });
      const url = URL.createObjectURL(blob);

      const a = document.createElement("a");
      a.href = url;
      a.download = `${(this.d.name || "contact").replace(/[^\w-]+/g, "_")}.vcf`;

      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);

      window.setTimeout(() => {
        URL.revokeObjectURL(url);
      }, 0);
    }

    renderVCardQR() {
      const container = document.getElementById("spax-photon-qr");
      if (!container) return;

      this.ensureQRCodeLib(() => {
        container.innerHTML = "";

        new QRCode(container, {
          text: this.generateVCard(),
          width: 180,
          height: 180,
          correctLevel: QRCode.CorrectLevel.M,
          colorDark: "#000000",
          colorLight: "#ffffff",
        });
      });
    }

    ensureQRCodeLib(cb) {
      if (window.QRCode) {
        cb();
      } else {
        this.reportRuntimeError(
          new Error(
            "QRCode library not found. Ensure qrcode.min.js is enqueued.",
          ),
          "ensureQRCodeLib",
          { eventType: "capability_failure", capability: "qrcode" },
        );
      }
    }

    openQRFullscreen() {
      if (document.getElementById("spax-photon-qr-fs")) return;

      if (!window.QRCode) {
        this.reportRuntimeError(
          new Error("QRCode library not available — fullscreen QR aborted."),
          "openQRFullscreen",
          { eventType: "capability_failure", capability: "qrcode" },
        );
        return;
      }

      const fs = document.createElement("div");

      fs.id = "spax-photon-qr-fs";
      fs.setAttribute("role", "dialog");
      fs.setAttribute("aria-modal", "true");
      fs.setAttribute("aria-label", "QR code fullscreen — tap to close");
      fs.setAttribute("tabindex", "-1");

      const inner = document.createElement("div");
      inner.id = "spax-photon-qr-fs-inner";

      const tip = document.createElement("p");
      tip.className = "spx-qr-fs-tip";
      tip.className = "spx-qr-fs-tip";
      tip.textContent = "☀ Increase brightness for best outdoor scan";
      tip.setAttribute("aria-hidden", "true");

      const label = document.createElement("p");
      label.className = "spx-qr-fs-label";
      label.className = "spx-qr-fs-label";
      label.textContent = "Tap anywhere to close";
      label.setAttribute("aria-hidden", "true");

      fs.appendChild(inner);
      fs.appendChild(tip);
      fs.appendChild(label);

      this.ensureQRCodeLib(() => {
        inner.innerHTML = "";

        const viewport = window.visualViewport || {
          width: window.innerWidth,
          height: window.innerHeight,
        };

        const overlayPaddingPx = 48;
        const reservedVerticalSpacePx = 96;
        const maxQrSizePx = 300;
        const minQrSizePx = 160;

        const availableWidthPx = Math.max(
          minQrSizePx,
          Math.floor(viewport.width - overlayPaddingPx),
        );

        const availableHeightPx = Math.max(
          minQrSizePx,
          Math.floor(
            viewport.height - overlayPaddingPx - reservedVerticalSpacePx,
          ),
        );

        const qrSizePx = Math.max(
          minQrSizePx,
          Math.min(maxQrSizePx, availableWidthPx, availableHeightPx),
        );

        new QRCode(inner, {
          text: this.generateVCard(),
          width: qrSizePx,
          height: qrSizePx,
          correctLevel: QRCode.CorrectLevel.M,
        });
      });

      const previouslyFocused =
        document.activeElement instanceof HTMLElement
          ? document.activeElement
          : null;

      const inertSiblings = [];

      const focusableSelector = [
        "a[href]",
        "area[href]",
        "button:not([disabled])",
        'input:not([disabled]):not([type="hidden"])',
        "select:not([disabled])",
        "textarea:not([disabled])",
        "iframe",
        "object",
        "embed",
        '[contenteditable="true"]',
        '[tabindex]:not([tabindex="-1"])',
      ].join(", ");

      const getFocusableElements = () =>
        Array.from(fs.querySelectorAll(focusableSelector)).filter((el) => {
          if (!(el instanceof HTMLElement)) return false;
          if (el.hidden) return false;

          const style = window.getComputedStyle(el);

          return style.display !== "none" && style.visibility !== "hidden";
        });

      const setBackgroundInert = () => {
        Array.from(document.body.children).forEach((child) => {
          if (!(child instanceof HTMLElement) || child === fs) return;

          inertSiblings.push({
            element: child,
            hadInert: child.inert,
          });

          child.inert = true;
        });
      };

      const restoreBackgroundInert = () => {
        inertSiblings.forEach(({ element, hadInert }) => {
          element.inert = hadInert;
        });
      };

      const close = ({ restoreFocus = true } = {}) => {
        this._qrFsClose = null;
        restoreBackgroundInert();
        fs.remove();

        if (!restoreFocus) return;

        const qrEl = document.getElementById("spax-photon-qr");

        if (qrEl instanceof HTMLElement) {
          qrEl.focus();
          return;
        }

        if (previouslyFocused) {
          previouslyFocused.focus();
        }
      };

      this._qrFsClose = close;

      fs.addEventListener("click", close);

      fs.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
          e.stopPropagation();
          close();
          return;
        }

        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          close();
          return;
        }

        if (e.key !== "Tab") return;

        const focusableElements = getFocusableElements();

        if (!focusableElements.length) {
          e.preventDefault();
          fs.focus();
          return;
        }

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (e.shiftKey) {
          if (
            document.activeElement === firstElement ||
            document.activeElement === fs
          ) {
            e.preventDefault();
            lastElement.focus();
          }

          return;
        }

        if (document.activeElement === lastElement) {
          e.preventDefault();
          firstElement.focus();
        }
      });

      document.body.appendChild(fs);
      setBackgroundInert();
      fs.focus();
    }

    _whatsappNumber() {
      const channels = Array.isArray(this.d.channels) ? this.d.channels : [];
      const wa = channels.find((c) => c.key === "whatsapp");

      return wa ? String(wa.val || "").trim() : "";
    }

    _whatsappUrl() {
      const raw = this._whatsappNumber();

      if (!raw) return "";

      const digits = raw.replace(/[^\d]/g, "");

      return digits ? `https://wa.me/${digits}` : "";
    }

    async keepScreenAwake() {
      if (!("wakeLock" in navigator)) return;

      try {
        this.wakeLock = await navigator.wakeLock.request("screen");

        this._onVisChange = async () => {
          if (!this.state.isActive) return;

          if (document.visibilityState === "visible" && !this.wakeLock) {
            try {
              this.wakeLock = await navigator.wakeLock.request("screen");
            } catch (error) {
              this.reportRuntimeError(
                error,
                "keepScreenAwake.visibilitychange",
              );
            }
          }
        };

        document.addEventListener("visibilitychange", this._onVisChange);
      } catch (error) {
        this.reportRuntimeError(error, "keepScreenAwake");
      }
    }

    releaseWakeLock() {
      try {
        if (this.wakeLock) {
          this.wakeLock.release();
          this.wakeLock = null;
        }
      } catch (error) {
        this.reportRuntimeError(error, "releaseWakeLock");
      }

      if (this._onVisChange) {
        document.removeEventListener("visibilitychange", this._onVisChange);
        this._onVisChange = null;
      }
    }

    vibrate(pattern) {
      if (!navigator.vibrate || typeof navigator.vibrate !== "function") return;

      try {
        navigator.vibrate(pattern);
      } catch (error) {
        this.reportRuntimeError(error, "vibrate");
      }
    }

    _canShare() {
      return typeof navigator.share === "function";
    }

    _canSendFile() {
      if (
        typeof navigator.share !== "function" ||
        typeof navigator.canShare !== "function"
      ) {
        return false;
      }

      try {
        const blob = new Blob(["test"], { type: "text/vcard" });
        const file = new File([blob], "test.vcf", { type: "text/vcard" });

        return navigator.canShare({ files: [file] });
      } catch (error) {
        this.reportRuntimeError(error, "_canSendFile");
        return false;
      }
    }

    _sendButtonLabel() {
      const ua = navigator.userAgent || "";

      if (/iphone|ipad|ipod/i.test(ua)) return "Send via AirDrop";
      if (/android/i.test(ua)) return "Nearby Share";

      return "Send Contact";
    }

    getInitials() {
      return (
        (this.d.name || "")
          .trim()
          .split(/\s+/)
          .filter(Boolean)
          .slice(0, 2)
          .map((w) => w.charAt(0))
          .join("")
          .toUpperCase() || "?"
      );
    }

    toTelHrefValue(value) {
      const raw = String(value || "").trim();

      if (raw.length === 0) {
        return "";
      }

      let extension = "";
      let main = raw;

      const lower = raw.toLowerCase();
      const extIndex = lower.lastIndexOf(" ext");

      if (extIndex !== -1) {
        const extPart = raw.slice(extIndex);
        let digits = "";

        for (let i = 0; i < extPart.length; i++) {
          const c = extPart.charCodeAt(i);

          if (c >= 48 && c <= 57) { // 48-57 = '0'-'9'
            digits += extPart[i];
          }
        }

        if (digits.length > 0) {
          extension = digits;
          main = raw.slice(0, extIndex).trim();
        }
      }

      let normalized = "";
      let hasLeadingPlus = false;

      for (let i = 0; i < main.length; i++) {
        const c = main.charCodeAt(i);

        if (i === 0 && c === 43) { // 43 = '+'
          hasLeadingPlus = true;
          continue;
        }

        if (c >= 48 && c <= 57) { // 48-57 = '0'-'9'
          normalized += main[i];
        }
      }

      if (normalized.length === 0) {
        return "";
      }

      let result = hasLeadingPlus ? `+${normalized}` : normalized;

      if (extension) {
        result += `;ext=${extension}`;
      }

      return result;
    }

    iconSave() {
      return `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`;
    }

    iconShare() {
      return `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>`;
    }

    iconSend() {
      return `<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>`;
    }

    iconWhatsApp() {
      return `<svg viewBox="0 0 24 24" aria-hidden="true" class="spx-wa-icon"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.867-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.521.149-.172.198-.296.298-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>`;
    }

    esc(str) {
      if (!str) return "";

      const d = document.createElement("div");
      d.textContent = String(str);

      return d.innerHTML;
    }

    vcardEsc(str) {
      if (!str) return "";

      return String(str)
        .replace(/\\/g, "\\\\")
        .replace(/;/g, "\\;")
        .replace(/,/g, "\\,")
        .replace(/\r\n|\r|\n/g, "\\n");
    }

    escAttr(str) {
      if (!str) return "";

      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
    }

    safeURL(url) {
      if (!url) return "";

      try {
        const u = new URL(url, window.location.href);

        if (["http:", "https:"].includes(u.protocol)) {
          return u.href;
        }
      } catch {
        // Invalid URL.
      }

      return "";
    }
  }

  function spx_photon_boot() {
    if (
      typeof CSS !== "undefined" &&
      typeof CSS.registerProperty === "function" &&
      !(navigator.connection && navigator.connection.saveData)
    ) {
      document.documentElement.classList.add("spx-houdini");
    }

    window.spxPhotonVCard = new SpxPhotonVCard();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", spx_photon_boot);
  } else {
    spx_photon_boot();
  }
})(window, document);
