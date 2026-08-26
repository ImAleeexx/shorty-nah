/**
 * The instance's icon set.
 *
 * A single module for two reasons. One weight everywhere — a mixed set reads as
 * an accident — and one place for a lint rule to point at, so an icon cannot
 * arrive from somewhere else by habit.
 *
 * Regular, not Light. At the sizes a data-dense interface uses, Light loses its
 * strokes against a hairline border. Bold is reserved for an active or selected
 * state, where the weight change carries the meaning.
 */
export {
  ArrowSquareOut,
  ArrowsClockwise,
  Check,
  CaretDown,
  CaretRight,
  ChartLine,
  Copy,
  Eye,
  EyeSlash,
  FunnelSimple,
  Gear,
  Globe,
  Link as LinkIcon,
  LockKey,
  MagnifyingGlass,
  Monitor,
  Moon,
  Plus,
  Prohibit,
  SignOut,
  Sun,
  Tag,
  Trash,
  Users,
  Warning,
  X,
} from '@phosphor-icons/react/dist/ssr';

/** Every icon renders at this weight unless a selected state calls for Bold. */
export const ICON_WEIGHT = 'regular' as const;

export const ICON_WEIGHT_ACTIVE = 'bold' as const;
