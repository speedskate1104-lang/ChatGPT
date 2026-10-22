import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('audit', Path(__file__).parents[1] / 'scripts/audit.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)

class VersionRangeTests(unittest.TestCase):
    def test_fixed_release_is_excluded(self):
        operators = {'max_version': '5.3.2', 'max_operator': 'lt'}
        self.assertTrue(audit.affected('5.3.1', operators))
        self.assertFalse(audit.affected('5.3.2', operators))
        self.assertFalse(audit.affected('6.2.3', operators))

    def test_inclusive_bounded_range(self):
        operators = {'min_version': '1.2', 'min_operator': 'ge', 'max_version': '2.0', 'max_operator': 'le'}
        self.assertFalse(audit.affected('1.1.9', operators))
        self.assertTrue(audit.affected('1.2.0', operators))
        self.assertTrue(audit.affected('2.0', operators))
        self.assertFalse(audit.affected('2.0.1', operators))

    def test_unknown_ranges_do_not_pass(self):
        for version, operators in [('1.0', {}), ('1.0', {'max_version': '2.0'}),
                                   ('1.0-beta', {'max_version': '2.0', 'max_operator': 'lt'}),
                                   ('1.0', {'max_version': '2.0', 'max_operator': 'unknown'})]:
            with self.assertRaises(ValueError):
                audit.affected(version, operators)

if __name__ == '__main__':
    unittest.main()
