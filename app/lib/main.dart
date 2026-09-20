import 'package:flutter/material.dart';

import 'config/app_config.dart';
import 'config/theme.dart';
import 'screens/auth/create_transaction_pin_screen.dart';
import 'screens/auth/login_screen.dart';
import 'screens/shell/main_shell.dart';
import 'services/session_inactivity_guard.dart';
import 'state/auth_controller.dart';
import 'widgets/ec_logo.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const EbubeConnectApp());
}

class EbubeConnectApp extends StatefulWidget {
  const EbubeConnectApp({super.key});

  @override
  State<EbubeConnectApp> createState() => _EbubeConnectAppState();
}

class _EbubeConnectAppState extends State<EbubeConnectApp> {
  final _auth = AuthController();
  bool _sessionWatchArmed = false;
  bool _bootstrapping = true;

  @override
  void initState() {
    super.initState();
    SessionInactivityGuard.setOnExpire(() async {
      await SessionInactivityGuard.instance.disable();
      await _auth.logout();
    });
    SessionInactivityGuard.instance.attach();
    _auth.addListener(_onAuthChanged);
    _auth.bootstrap().then((_) async {
      try {
        if (_auth.user != null) {
          // Restore without stamping activity so idle sessions still expire.
          await SessionInactivityGuard.instance.enforceOnColdStart();
          _sessionWatchArmed = _auth.user != null;
        }
      } finally {
        _bootstrapping = false;
      }
    });
  }

  void _onAuthChanged() {
    if (_auth.user != null) {
      // Skip during bootstrap — cold start arms without a fresh stamp.
      if (_bootstrapping) return;
      if (!_sessionWatchArmed) {
        SessionInactivityGuard.instance.enable(stampActivity: true);
        _sessionWatchArmed = true;
      }
    } else {
      _sessionWatchArmed = false;
      SessionInactivityGuard.instance.disable();
    }
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    SessionInactivityGuard.instance.detach();
    _auth.dispose();
    super.dispose();
  }

  String _gateKey() {
    if (!_auth.ready) return 'boot';
    final user = _auth.user;
    if (user == null) return 'login';
    if (!user.hasTransactionPin) return 'txn-pin:${user.id}';
    return 'app:${user.id}';
  }

  Widget _homeForGate(String gate) {
    if (gate == 'boot') {
      return const Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              EcLogo(size: 72, showWordmark: true),
              SizedBox(height: 28),
              CircularProgressIndicator(),
            ],
          ),
        ),
      );
    }
    if (gate == 'login') return const LoginScreen();
    if (gate.startsWith('txn-pin')) return const CreateTransactionPinScreen();
    return const MainShell();
  }

  @override
  Widget build(BuildContext context) {
    return AuthScope(
      controller: _auth,
      child: AnimatedBuilder(
        animation: _auth,
        builder: (context, _) {
          final gate = _gateKey();
          return Listener(
            behavior: HitTestBehavior.translucent,
            onPointerDown: (_) {
              SessionInactivityGuard.instance.recordActivity();
            },
            child: MaterialApp(
              key: ValueKey(gate),
              title: AppConfig.appName,
              debugShowCheckedModeBanner: false,
              theme: buildEcTheme(),
              home: _homeForGate(gate),
            ),
          );
        },
      ),
    );
  }
}
