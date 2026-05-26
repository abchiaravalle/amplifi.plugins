import MetaDescriptionCard from './MetaDescriptionCard';
import AltTextCard from './AltTextCard';
import TitleCard from './TitleCard';
import UnpublishCard from './UnpublishCard';

const map = {
	meta_description: MetaDescriptionCard,
	alt_text: AltTextCard,
	title: TitleCard,
	unpublish: UnpublishCard,
};

export default function SuggestionCard( props ) {
	const { suggestion } = props;
	const Component = map[ suggestion.fix_type ] || MetaDescriptionCard;
	return <Component { ...props } />;
}
