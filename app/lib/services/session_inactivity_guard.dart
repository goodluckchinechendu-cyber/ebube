import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Ends the session after [timeout] without recorded activity.
class SessionInactivityGuard with WidgetsBindingObserver {
  SessionInactivityGuard._();

  static final SessionInactivityGuard instance = SessionInactivityGuard._();

  static const Duration timeout = Duration(minutes: 10);
  static const _lastActivityKey = 'session_last_activity_ms';

  static Future<void> Function()? onExpire;

  static void setOnExpire(Future<void> Function() callback) {
    onExpire = callback;
  }

  bool _attached = false;
  bool _checking = false;
  bool _enabled = false;
  Timer? _foregroundTimer;

  /// Enable checks after login / successful cold-start restore.
  ///
  /// [stampActivity] writes a fresh last-activity timestamp (login).
  /// Pass false on cold start so an old idle session can still expire.
  void enable({bool stampActivity = true}) {
    _enabled = true;
    if (stampActivity) {
      unawaited(recordActivity());
    } else {
      resetForegroundTimer();
    }
  }

  Future<void> disable() async {
    _enabled = false;
    _foregroundTimer?.cancel();
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_lastActivityKey);
  }

  Future<void> recordActivity() async {
    if (!_enabled) return;
    final now = DateTime.now().millisecondsSinceEpoch;
    final prefs = await SharedPreferences.getInstance();
    final last = prefs.getInt(_lastActivityKey) ?? 0;
    if (now - last < 2000) {
      resetForegroundTimer();
      return;
    }
    await prefs.setInt(_lastActivityKey, now);
    resetForegroundTimer();
  }

  void resetForegroundTimer() {
    _foregroundTimer?.cancel();
    if (!_enabled) return;
    _foregroundTimer = Timer(timeout, () {
      unawaited(_expireIfIdleTooLong());
    });
  }

  void attach({Future<void> Function()? onExpireCallback}) {
    if (onExpireCallback != null) {
      onExpire = onExpireCallback;
    }
    if (_attached) return;
    WidgetsBinding.instance.addObserver(this);
    _attached = true;
  }

  void detach() {
    if (!_attached) return;
    WidgetsBinding.instance.removeObserver(this);
    _foregroundTimer?.cancel();
    _attached = false;
  }

  /// After restore: enable without stamping, then expire if already idle.
  Future<void> enforceOnColdStart() async {
    enable(stampActivity: false);
    await _expireIfIdleTooLong();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused:
      case AppLifecycleState.hidden:
        _foregroundTimer?.cancel();
        break;
      case AppLifecycleState.inactive:
        break;
      case AppLifecycleState.resumed:
        unawaited(_onResumed());
        break;
      case AppLifecycleState.detached:
        break;
    }
  }

  Future<void> _onResumed() async {
    if (!_enabled) return;
    await _expireIfIdleTooLong();
  }

  Future<void> _expireIfIdleTooLong() async {
    if (_checking || !_enabled) return;
    _checking = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      final lastMs = prefs.getInt(_lastActivityKey);
      if (lastMs == null) {
        await recordActivity();
        return;
      }

      final idleFor = DateTime.now().difference(
        DateTime.fromMillisecondsSinceEpoch(lastMs),
      );
      if (idleFor < timeout) {
        final remaining = timeout - idleFor;
        _foregroundTimer?.cancel();
        _foregroundTimer = Timer(remaining, () {
          unawaited(_expireIfIdleTooLong());
        });
        return;
      }

      final cb = onExpire;
      if (cb != null) {
        await cb();
      } else {
        await disable();
      }
    } finally {
      _checking = false;
    }
  }
}
