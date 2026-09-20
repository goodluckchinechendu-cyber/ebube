import 'package:flutter_test/flutter_test.dart';

import 'package:ebube_connect/main.dart';

void main() {
  testWidgets('EbubeConnect boots to splash/login', (tester) async {
    await tester.pumpWidget(const EbubeConnectApp());
    await tester.pump();
    expect(find.textContaining('EbubeConnect'), findsWidgets);
  });
}
